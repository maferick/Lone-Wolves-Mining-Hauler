<?php
declare(strict_types=1);

namespace App\Services;

final class RouteException extends \RuntimeException
{
  private array $details;

  public function __construct(string $message, array $details = [], int $code = 0, ?\Throwable $previous = null)
  {
    parent::__construct($message, $code, $previous);
    $this->details = $details;
  }

  public function getDetails(): array
  {
    return $this->details;
  }
}
