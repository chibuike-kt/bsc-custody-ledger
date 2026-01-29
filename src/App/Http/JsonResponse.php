<?php
declare(strict_types=1);

namespace App\App\Http;

final class JsonResponse
{
  public function __construct(private int $status, private array $body) {}

  public static function ok(array $body): self { return new self(200, $body); }
  public static function created(array $body): self { return new self(201, $body); }
  public static function badRequest(string $code): self { return new self(400, ['error' => $code]); }
  public static function unauthorized(): self { return new self(401, ['error' => 'unauthorized']); }
  public static function forbidden(): self { return new self(403, ['error' => 'forbidden']); }
  public static function notFound(): self { return new self(404, ['error' => 'not_found']); }

  public function send(): void
  {
    http_response_code($this->status);
    header('Content-Type: application/json');
    echo json_encode($this->body, JSON_UNESCAPED_SLASHES);
  }
}
