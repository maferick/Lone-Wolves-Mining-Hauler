<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/EsiService.php
 *
 * Facade service that wires client + token refresh + corp contracts sync.
 */
final class EsiService
{
  private EsiClient $client;
  private SsoService $sso;
  private CorpContractsService $contracts;

  public function __construct(private Db $db, private array $config)
  {
    $this->client = new EsiClient($db, $config);
    $this->sso = new SsoService($db, $config);
    $this->contracts = new CorpContractsService($db, $config, $this->client, $this->sso);
  }

  public function ping(): array
  {
    return [
      'ok' => true,
      'esi_cache_enabled' => (bool)($this->config['esi']['cache']['enabled'] ?? true),
      'esi_base' => $this->config['esi']['endpoints']['esi_base'] ?? null,
      'sso_token_url' => $this->config['esi']['endpoints']['sso_token'] ?? null,
    ];
  }

  public function client(): EsiClient { return $this->client; }
  public function sso(): SsoService { return $this->sso; }
  public function contracts(): CorpContractsService { return $this->contracts; }
}
