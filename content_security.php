<?php

function safeRichHtml($html): string
{
    $html = (string)$html;
    if ($html === '') return '';
    if (!class_exists('DOMDocument')) return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="safe-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $allowed = ['div','p','br','strong','b','em','i','u','s','ul','ol','li','blockquote','h3','h4','a'];
    $nodes = [];
    foreach ($document->getElementsByTagName('*') as $node) $nodes[] = $node;
    foreach (array_reverse($nodes) as $node) {
        if ($node->getAttribute('id') === 'safe-root') continue;
        if (!in_array(strtolower($node->nodeName), $allowed, true)) {
            $text = $document->createTextNode($node->textContent);
            $node->parentNode?->replaceChild($text, $node);
            continue;
        }
        foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->name);
            if ($node->nodeName !== 'a' || !in_array($name, ['href','target','rel'], true)) $node->removeAttribute($attribute->name);
        }
        if ($node->nodeName === 'a') {
            $href = trim($node->getAttribute('href'));
            if ($href !== '' && !preg_match('~^(https?://|mailto:|tel:)~i', $href)) $node->removeAttribute('href');
            if ($node->getAttribute('target') === '_blank') $node->setAttribute('rel', 'noopener noreferrer');
        }
    }
    $root = $document->getElementById('safe-root');
    if (!$root) return '';
    $result = '';
    foreach ($root->childNodes as $child) $result .= $document->saveHTML($child);
    return $result;
}
