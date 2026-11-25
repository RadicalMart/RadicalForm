<?php

namespace Joomla\Plugin\System\RadicalForm\Helper;

defined('_JEXEC') or die;

final class FormMessage
{
    public const HTML = 'html';
    public const LINKED_HTML = 'linked-html';
    public const MESSENGER = 'messenger';
    public const PLAIN = 'plain';

    private const LINE_BREAK = '<br[ \t]*/?>';
    private const RULE = '<hr[ \t]*/?>';

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function text(mixed $value, string $separator = ', '): string
    {
        if (is_array($value))
        {
            $parts = [];
            if (preg_match('~\A' . self::LINE_BREAK . '\z~i', $separator))
            {
                $parts[] = ' ';
            }

            foreach ($value as $item)
            {
                $parts[] = self::text($item, $separator);
            }

            return implode($separator, $parts);
        }

        if ($value === null || is_scalar($value))
        {
            return (string) $value;
        }

        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public static function plain(string $text): string
    {
        return preg_replace('~' . self::LINE_BREAK . '~i', " \r\n", $text) ?? $text;
    }

    public static function format(mixed $value, string $separator, string $format, bool $prependLineBreak = true): string
    {
        if (!is_array($value))
        {
            $text = self::text($value);

            return $format === self::PLAIN ? self::plain($text) : self::render($text, $format === self::LINKED_HTML);
        }

        $parts = [];
        if ($prependLineBreak && preg_match('~\A' . self::LINE_BREAK . '\z~i', $separator))
        {
            $parts[] = ' ';
        }

        foreach ($value as $item)
        {
            $parts[] = self::format($item, $separator, $format);
        }

        return implode(self::formatSeparator($separator, $format), $parts);
    }

    private static function formatSeparator(string $separator, string $format): string
    {
        $parts = preg_split('~(' . self::LINE_BREAK . '|' . self::RULE . ')~i', $separator, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false)
        {
            return $format === self::PLAIN ? $separator : self::escape($separator);
        }

        foreach ($parts as $index => $part)
        {
            if ($index % 2 === 0)
            {
                $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $parts[$index] = $format === self::PLAIN ? $text : self::escape($text);
            }
            elseif ($format === self::PLAIN)
            {
                $parts[$index] = " \r\n";
            }
            else
            {
                $parts[$index] = $format !== self::MESSENGER && strncasecmp($part, '<hr', 3) === 0 ? '<hr />' : '<br />';
            }
        }

        return implode('', $parts);
    }

    private static function render(string $text, bool $linkify): string
    {
        $pattern = self::LINE_BREAK;
        if ($linkify)
        {
            $pattern .= '|https?://[^\s<>"\']+';
        }

        $parts = preg_split('~(' . $pattern . ')~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false)
        {
            return self::escape($text);
        }

        $html = '';
        foreach ($parts as $index => $part)
        {
            if ($index % 2 === 0)
            {
                $html .= self::escape($part);
            }
            elseif ($part[0] === '<')
            {
                $html .= '<br />';
            }
            else
            {
                $url = rtrim($part, '.,:;');
                $path = parse_url($url, PHP_URL_PATH);
                $name = $path ? basename($path) : '';
                $label = str_contains($name, '.') ? $name : $url;
                $html .= self::webLink($url, $label, 'rf-history-file-link') . self::escape(substr($part, strlen($url)));
            }
        }

        return $html;
    }

    public static function webLink(string $url, ?string $label = null, string $class = ''): string
    {
        $label = self::escape($label ?? $url);
        if (str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url))
        {
            return $label;
        }

        $parts = parse_url($url);
        if (empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true))
        {
            return $label;
        }

        $classAttribute = $class !== '' ? ' class="' . self::escape($class) . '"' : '';

        return '<a href="' . self::escape($url) . '" target="_blank" rel="noopener noreferrer"' . $classAttribute . '>' . $label . '</a>';
    }
}
