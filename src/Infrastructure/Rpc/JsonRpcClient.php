<?php
declare(strict_types=1);

namespace App\Infrastructure\Rpc;

use GuzzleHttp\Client;

final class JsonRpcClient
{
  private Client $http;

  public function __construct(private string $url)
  {
    $this->http = new Client(['timeout' => 20]);
  }

  public function call(string $method, array $params = []): mixed
  {
    $payload = [
      'jsonrpc' => '2.0',
      'id' => 1,
      'method' => $method,
      'params' => $params,
    ];

    $res = $this->http->post($this->url, ['json' => $payload]);
    $data = json_decode((string)$res->getBody(), true);

    if (!is_array($data)) throw new \RuntimeException('rpc_invalid_response');
    if (isset($data['error'])) throw new \RuntimeException('rpc_error:' . json_encode($data['error']));
    return $data['result'] ?? null;
  }
}
