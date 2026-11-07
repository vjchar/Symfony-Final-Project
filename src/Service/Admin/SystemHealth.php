<?php

namespace App\Service\Admin;

use App\Service\PrivateFileStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * v15 health.php: deployment-readiness checks for the database, upload
 * folders and security settings, adapted to the Symfony application.
 */
final class SystemHealth
{
    public const MINIMUM_PHP = '8.4.0';

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        #[Autowire('%app.private_storage_dir%')] private readonly string $privateStorage,
        #[Autowire('%kernel.project_dir%/public/images/cars')] private readonly string $vehicleImages,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    /**
     * @return list<array{label: string, passed: bool, detail: string}>
     */
    public function checks(): array
    {
        [$connected, $databaseName, $connectionDetail] = $this->database();
        $expectedName = (string) ($this->connection->getParams()['dbname'] ?? '');
        $production = 'prod' === $this->environment;

        $checks = [
            $this->check('PHP version', version_compare(\PHP_VERSION, self::MINIMUM_PHP, '>='), \PHP_VERSION.' (8.4+ required)'),
            $this->check('MySQL connection', $connected, $connectionDetail),
            $this->check('Database name', $connected && $databaseName === $expectedName, '' !== $databaseName ? $databaseName : 'Not connected'),
            $this->check('PDO MySQL driver', \in_array('mysql', \PDO::getAvailableDrivers(), true), implode(', ', \PDO::getAvailableDrivers()) ?: 'None'),
        ];
        foreach ([PrivateFileStorage::DOCUMENTS => 'Document uploads writable', PrivateFileStorage::PAYMENT_PROOFS => 'Payment proofs writable', PrivateFileStorage::REVIEWS => 'Review uploads writable'] as $area => $label) {
            $directory = $this->privateStorage.\DIRECTORY_SEPARATOR.$area;
            $checks[] = $this->check($label, $this->writable($directory), $directory);
        }
        $checks[] = $this->check('Vehicle images writable', $this->writable($this->vehicleImages), $this->vehicleImages);
        $checks[] = $this->check('Application secret', \strlen($this->secret) >= 32, \strlen($this->secret) >= 32 ? 'Configured' : 'Set APP_SECRET to at least 32 characters');
        $checks[] = $this->check('Debug disabled', !$this->debug, $this->debug ? 'Enabled ('.$this->environment.' environment)' : 'Disabled');
        $checks[] = $this->check(
            'HTTPS',
            !$production || true === $this->requestStack->getCurrentRequest()?->isSecure(),
            $production ? 'Required on the public site' : 'Optional for local development',
        );

        return $checks;
    }

    /**
     * @param list<array{label: string, passed: bool, detail: string}> $checks
     */
    public static function allPassed(array $checks): bool
    {
        return [] === array_filter($checks, static fn (array $check): bool => !$check['passed']);
    }

    /**
     * @return array{bool, string, string} connected, current database name, detail
     */
    private function database(): array
    {
        try {
            $name = (string) $this->connection->fetchOne('SELECT DATABASE()');
            $platform = $this->connection->getDatabasePlatform();

            return [$platform instanceof AbstractMySQLPlatform, $name, $platform instanceof AbstractMySQLPlatform ? 'MySQL connected' : $platform::class.' connected'];
        } catch (\Throwable $error) {
            return [false, '', 'Connection failed: '.$error->getMessage()];
        }
    }

    /**
     * Writable now, or creatable on first upload because its parent is writable.
     */
    private function writable(string $directory): bool
    {
        return is_dir($directory) ? is_writable($directory) : is_writable(\dirname($directory)) || (!is_dir(\dirname($directory)) && is_writable(\dirname($directory, 2)));
    }

    /**
     * @return array{label: string, passed: bool, detail: string}
     */
    private function check(string $label, bool $passed, string $detail): array
    {
        return ['label' => $label, 'passed' => $passed, 'detail' => $detail];
    }
}
