<?php
declare(strict_types=1);

namespace Studio\Slack;

/** Calls Slack Web API methods (https://docs.slack.dev/reference/methods). */
final class WebApi
{
    /**
     * @param string $mode json | form | get | multipart
     *   Most methods take JSON. OAuth and tooling methods want form fields, apps.icon.set wants a file upload.
     */
    /** The permissions a bot token actually has (Slack reports them in a response header). */
    public static function grantedScopes(string $token): string
    {
        $ch = curl_init('https://slack.com/api/auth.test');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        if (!preg_match('/^x-oauth-scopes:\s*([^\r\n]*)/mi', $raw, $m)) {
            throw new SlackError('auth.test', 'no_scopes_header');
        }
        $scopes = array_filter(array_map('trim', explode(',', $m[1])));
        sort($scopes);
        return implode(',', $scopes);
    }

    public static function call(string $method, array $params = [], ?string $token = null, string $mode = 'json'): array
    {
        $url = "https://slack.com/api/$method";
        $headers = [];
        if ($token !== null && $token !== '') {
            $headers[] = "Authorization: Bearer $token";
        }
        $ch = curl_init();
        switch ($mode) {
            case 'get':
                $url .= '?' . http_build_query($params);
                break;
            case 'form':
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                break;
            case 'multipart':
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params); // may contain CURLFile
                break;
            default:
                $headers[] = 'Content-Type: application/json; charset=utf-8';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new SlackError($method, $err ?: 'no response from Slack');
        }
        if (empty($data['ok'])) {
            throw new SlackError($method, (string) ($data['error'] ?? 'unknown_error'), $data);
        }
        return $data;
    }
}
