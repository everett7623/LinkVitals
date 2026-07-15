#!/usr/bin/env python3
"""
Generate .mo file from .po file
Run: python generate-mo.py
"""

import struct
import re

def unescape_po_string(value):
    """Unescape PO string escapes without corrupting non-ASCII text."""
    result = []
    i = 0

    while i < len(value):
        char = value[i]
        if char != '\\':
            result.append(char)
            i += 1
            continue

        i += 1
        if i >= len(value):
            result.append('\\')
            break

        escaped = value[i]
        result.append({
            'n': '\n',
            'r': '\r',
            't': '\t',
            '"': '"',
            '\\': '\\',
        }.get(escaped, escaped))
        i += 1

    return ''.join(result)

def generate_mo(po_file, mo_file):
    with open(po_file, 'r', encoding='utf-8') as f:
        content = f.read()

    # Normalize line endings to LF
    content = content.replace('\r\n', '\n').replace('\r', '\n')

    translations = {}

    # Parse msgid/msgstr pairs
    pattern = r'msgid\s+"((?:[^"\\]|\\.)*)"\nmsgstr\s+"((?:[^"\\]|\\.)*)"'
    matches = re.findall(pattern, content)

    for match in matches:
        msgid = unescape_po_string(match[0])
        msgstr = unescape_po_string(match[1])
        if msgid != '' and msgstr != '':
            translations[msgid] = msgstr

    # Sort by msgid
    translations = dict(sorted(translations.items()))

    num = len(translations)
    offsets = []
    strs_offsets = []
    ids = b''
    strs = b''

    for id, str in translations.items():
        id_bytes = id.encode('utf-8')
        str_bytes = str.encode('utf-8')
        offsets.append([len(id_bytes), len(ids)])
        ids += id_bytes + b'\0'
        strs_offsets.append([len(str_bytes), len(strs)])
        strs += str_bytes + b'\0'

    header_size = 28
    key_table_offset = header_size
    val_table_offset = header_size + num * 8
    key_data_offset = header_size + num * 16
    val_data_offset = key_data_offset + len(ids)

    mo = struct.pack('<I', 0x950412de)  # magic number
    mo += struct.pack('<I', 0)  # revision
    mo += struct.pack('<I', num)  # number of strings
    mo += struct.pack('<I', key_table_offset)  # offset of original strings table
    mo += struct.pack('<I', val_table_offset)  # offset of translated strings table
    mo += struct.pack('<I', 0)  # hash table size (0 = none)
    mo += struct.pack('<I', 0)  # hash table offset

    # Original strings table
    for off in offsets:
        mo += struct.pack('<I', off[0])  # length
        mo += struct.pack('<I', key_data_offset + off[1])  # offset

    # Translated strings table
    for off in strs_offsets:
        mo += struct.pack('<I', off[0])  # length
        mo += struct.pack('<I', val_data_offset + off[1])  # offset

    mo += ids
    mo += strs

    with open(mo_file, 'wb') as f:
        f.write(mo)

    print(f"Generated: {mo_file} ({num} strings)")

if __name__ == '__main__':
    po = 'linkvitals/languages/linkvitals-zh_CN.po'
    mo = 'linkvitals/languages/linkvitals-zh_CN.mo'
    generate_mo(po, mo)
