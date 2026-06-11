<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\I18n\Translator;

class FrontRoutei18n extends FrontRoute{
    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));
        $body = json_encode($translator->jsMessages(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $etag = '"' . hash('sha256', $body) . '"';

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');

        if ((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return "";
        }

        if ($method !== 'HEAD') {
            return $body;
        }
        return "";
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        return "";
    }
}
