<?php
/**
 * AI 知识库上传辅助函数
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once dirname(__DIR__, 2) . '/includes/knowledge-retrieval.php';

function knowledge_base_abs_path(string $relativePath): string {
    return dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/');
}

function cleanup_knowledge_file(?string $relativePath): void {
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return;
    }

    $absolutePath = knowledge_base_abs_path($relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function normalize_knowledge_text(string $text): string {
    return knowledge_retrieval_normalize_text($text);
}

function convert_uploaded_text_to_utf8(string $text): string {
    if ($text === '') {
        return '';
    }

    $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
    if (!$detectedEncoding || strtoupper($detectedEncoding) === 'UTF-8') {
        return $text;
    }

    $converted = @mb_convert_encoding($text, 'UTF-8', $detectedEncoding);
    return $converted === false ? $text : $converted;
}

function extract_zip_entry_via_ziparchive(string $filepath, string $entryName): string {
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return '';
    }

    $content = $zip->getFromName($entryName);
    $zip->close();
    return $content === false ? '' : (string) $content;
}

function extract_zip_entry_via_php_fallback(string $filepath, string $entryName): string {
    $binary = @file_get_contents($filepath);
    if ($binary === false || strlen($binary) < 22) {
        return '';
    }

    $eocdSignature = "PK\x05\x06";
    $eocdOffset = strrpos(substr($binary, max(0, strlen($binary) - 65557)), $eocdSignature);
    if ($eocdOffset === false) {
        return '';
    }
    $eocdOffset += max(0, strlen($binary) - 65557);

    $eocd = unpack(
        'Vsignature/vdisk/vdiskStart/ventriesDisk/ventriesTotal/VcentralSize/VcentralOffset/vcommentLength',
        substr($binary, $eocdOffset, 22)
    );
    if (!$eocd || (int) ($eocd['signature'] ?? 0) !== 0x06054b50) {
        return '';
    }

    $centralOffset = (int) ($eocd['centralOffset'] ?? 0);
    $entriesTotal = (int) ($eocd['entriesTotal'] ?? 0);
    $cursor = $centralOffset;

    for ($index = 0; $index < $entriesTotal; $index++) {
        $header = unpack(
            'Vsignature/vversionMade/vversionNeeded/vflags/vcompression/vmodTime/vmodDate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttrs/VexternalAttrs/VlocalHeaderOffset',
            substr($binary, $cursor, 46)
        );

        if (!$header || (int) ($header['signature'] ?? 0) !== 0x02014b50) {
            return '';
        }

        $nameLength = (int) $header['nameLength'];
        $extraLength = (int) $header['extraLength'];
        $commentLength = (int) $header['commentLength'];
        $fileName = substr($binary, $cursor + 46, $nameLength);

        if ($fileName === $entryName) {
            $localOffset = (int) $header['localHeaderOffset'];
            $localHeader = unpack(
                'Vsignature/vversionNeeded/vflags/vcompression/vmodTime/vmodDate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength',
                substr($binary, $localOffset, 30)
            );

            if (!$localHeader || (int) ($localHeader['signature'] ?? 0) !== 0x04034b50) {
                return '';
            }

            $dataOffset = $localOffset + 30 + (int) $localHeader['nameLength'] + (int) $localHeader['extraLength'];
            $compressedSize = (int) $header['compressedSize'];
            $compressedData = substr($binary, $dataOffset, $compressedSize);
            $compression = (int) $header['compression'];

            if ($compression === 0) {
                return $compressedData;
            }

            if ($compression === 8) {
                $inflated = @gzinflate($compressedData);
                if ($inflated !== false) {
                    return $inflated;
                }

                if (function_exists('inflate_init') && function_exists('inflate_add')) {
                    $context = @inflate_init(ZLIB_ENCODING_RAW);
                    if ($context !== false) {
                        $inflated = @inflate_add($context, $compressedData, ZLIB_FINISH);
                        if ($inflated !== false) {
                            return $inflated;
                        }
                    }
                }
            }

            return '';
        }

        $cursor += 46 + $nameLength + $extraLength + $commentLength;
    }

    return '';
}

function extract_zip_entry_contents(string $filepath, string $entryName): string {
    $content = extract_zip_entry_via_ziparchive($filepath, $entryName);
    if ($content !== '') {
        return $content;
    }

    return extract_zip_entry_via_php_fallback($filepath, $entryName);
}

function extract_docx_inline_text(DOMNode $scope, DOMXPath $xpath): string {
    $parts = [];
    $nodes = $xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $scope);
    if ($nodes === false) {
        return '';
    }

    foreach ($nodes as $node) {
        $localName = $node->localName;
        if ($localName === 't') {
            $parts[] = $node->textContent;
        } elseif ($localName === 'tab') {
            $parts[] = "\t";
        } else {
            $parts[] = "\n";
        }
    }

    return normalize_knowledge_text(implode('', $parts));
}

function extract_docx_text_from_xml(string $xmlContent): string {
    if ($xmlContent === '') {
        return '';
    }

    $dom = new DOMDocument();
    $loaded = @$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    if (!$loaded) {
        return '';
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $blocks = [];
    $bodyChildren = $xpath->query('//w:body/*');
    if ($bodyChildren !== false) {
        foreach ($bodyChildren as $child) {
            if ($child->localName === 'p') {
                $paragraphText = extract_docx_inline_text($child, $xpath);
                if ($paragraphText !== '') {
                    $blocks[] = $paragraphText;
                }
                continue;
            }

            if ($child->localName === 'tbl') {
                $rows = [];
                $tableRows = $xpath->query('./w:tr', $child);
                if ($tableRows !== false) {
                    foreach ($tableRows as $row) {
                        $cells = [];
                        $tableCells = $xpath->query('./w:tc', $row);
                        if ($tableCells === false) {
                            continue;
                        }
                        foreach ($tableCells as $cell) {
                            $cellText = extract_docx_inline_text($cell, $xpath);
                            if ($cellText !== '') {
                                $cells[] = $cellText;
                            }
                        }
                        if (!empty($cells)) {
                            $rows[] = implode("\t", $cells);
                        }
                    }
                }

                if (!empty($rows)) {
                    $blocks[] = implode("\n", $rows);
                }
            }
        }
    }

    if (empty($blocks)) {
        $fallback = $xpath->query('//w:t');
        if ($fallback !== false) {
            foreach ($fallback as $node) {
                $value = normalize_knowledge_text($node->textContent ?? '');
                if ($value !== '') {
                    $blocks[] = $value;
                }
            }
        }
    }

    return normalize_knowledge_text(implode("\n\n", $blocks));
}

function extract_docx_content(string $filepath): string {
    if (!is_file($filepath)) {
        return '';
    }

    $xmlContent = extract_zip_entry_contents($filepath, 'word/document.xml');
    if ($xmlContent === '') {
        return '';
    }

    return extract_docx_text_from_xml($xmlContent);
}

function parse_uploaded_knowledge_file(string $filepath, string $originalName, string $extension): array {
    $extension = strtolower(trim($extension));

    if ($extension === 'txt' || $extension === 'md') {
        $rawContent = @file_get_contents($filepath);
        if ($rawContent === false) {
            throw new RuntimeException('文件内容读取失败');
        }

        $content = normalize_knowledge_text(convert_uploaded_text_to_utf8($rawContent));
        if ($content === '') {
            throw new RuntimeException('文件内容为空或无法读取');
        }

        return [
            'content' => $content,
            'file_type' => $extension === 'md' ? 'markdown' : 'text',
        ];
    }

    if ($extension === 'docx') {
        $content = extract_docx_content($filepath);
        if ($content === '') {
            throw new RuntimeException('DOCX 文本提取失败，请确认文件未损坏；如为旧版文档，请先另存为 .docx 后重新上传');
        }

        return [
            'content' => $content,
            'file_type' => 'word',
        ];
    }

    if ($extension === 'doc') {
        throw new RuntimeException('暂不支持旧版 .doc 直接解析，请先另存为 .docx 后上传');
    }

    throw new RuntimeException('不支持的文件格式');
}
