<?php
declare(strict_types=1);

namespace App\App\Http;

final class Request
{
  public function __construct(
    public string $method,
    public string $path,
    public array $headers,
    public array $query,
    public array $json,
    public ?string $userId = null
  ) {}

  public static function fromGlobals(): self
  {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    $headers = [];
    foreach ($_SERVER as $k => $v) {
      if (str_starts_with($k, 'HTTP_')) {
        $name = str_replace('_', '-', strtolower(substr($k, 5)));
        $headers[$name] = $v;
      }
    }

    $raw = file_get_contents('php://input') ?: '';
    $json = [];
    if ($raw !== '') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $json = $decoded;
    }

    return new self($method, $path, $headers, $_GET ?? [], $json);
  }
}
