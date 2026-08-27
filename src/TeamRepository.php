<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class TeamRepository
{
    private const TEAMS = ['XCTD', 'MNX'];

    private readonly string $timezone;

    public function __construct(private readonly PDO $pdo, string $timezone = 'Asia/Jakarta')
    {
        try {
            new DateTimeZone($timezone);
            $this->timezone = $timezone;
        } catch (\Throwable $e) {
            $this->timezone = 'Asia/Jakarta';
        }
    }

    /** @return array{id:int,display_name:string,alias:string,location:string,team:string,tracking_start_week:string} */
    public function findActiveSender(string $senderName, ?int $selectedSenderId = null): array
    {
        $normalized = self::normalizeName($senderName);
        if ($normalized === '') {
            throw new RuntimeException('Sender name could not be validated.');
        }

        // Alias is the unique operational identity. Prefer an exact active alias match.
        $aliasStatement = $this->pdo->prepare(
            'SELECT id, display_name, alias, location, team, tracking_start_week
             FROM team_members
             WHERE is_active = 1 AND normalized_alias = :normalized_alias
             LIMIT 1'
        );
        $aliasStatement->execute(['normalized_alias' => $normalized]);
        $aliasRow = $aliasStatement->fetch();
        if (is_array($aliasRow)) {
            $sender = $this->validatedSenderRow($aliasRow);
            if ($selectedSenderId !== null && $selectedSenderId > 0 && $selectedSenderId !== $sender['id']) {
                throw new RuntimeException('Selected SUBID does not match the detected sender. Transaction rejected.');
            }
            return $sender;
        }

        $rows = $this->findActiveSenderCandidatesByNormalizedName($normalized);
        if ($rows === []) {
            throw new RuntimeException('Sender is not registered in Senders & Teams. Transaction rejected.');
        }

        if (count($rows) === 1) {
            $sender = $rows[0];
            if ($selectedSenderId !== null && $selectedSenderId > 0 && $selectedSenderId !== $sender['id']) {
                throw new RuntimeException('Selected SUBID does not match the detected sender. Transaction rejected.');
            }
            return $sender;
        }

        if ($selectedSenderId === null || $selectedSenderId <= 0) {
            throw new RuntimeException('Sender matches multiple registered records. Select a SUBID before saving.');
        }

        foreach ($rows as $sender) {
            if ($sender['id'] === $selectedSenderId) {
                return $sender;
            }
        }

        throw new RuntimeException('Selected SUBID does not match the detected sender. Transaction rejected.');
    }

    /** @return array{sender_name:string,requires_selection:bool,selected_id:int|null,options:list<array{id:int,display_name:string,alias:string,location:string,team:string}>} */
    public function resolveActiveSenderOptions(string $senderName): array
    {
        $normalized = self::normalizeName($senderName);
        if ($normalized === '') {
            throw new RuntimeException('Sender name could not be validated.');
        }

        $aliasStatement = $this->pdo->prepare(
            'SELECT id, display_name, alias, location, team, tracking_start_week
             FROM team_members
             WHERE is_active = 1 AND normalized_alias = :normalized_alias
             LIMIT 1'
        );
        $aliasStatement->execute(['normalized_alias' => $normalized]);
        $aliasRow = $aliasStatement->fetch();
        if (is_array($aliasRow)) {
            $sender = $this->validatedSenderRow($aliasRow);
            return [
                'sender_name' => $sender['display_name'],
                'requires_selection' => false,
                'selected_id' => $sender['id'],
                'options' => [[
                    'id' => $sender['id'],
                    'display_name' => $sender['display_name'],
                    'alias' => $sender['alias'],
                    'location' => $sender['location'],
                    'team' => $sender['team'],
                ]],
            ];
        }

        $rows = $this->findActiveSenderCandidatesByNormalizedName($normalized);
        if ($rows === []) {
            throw new RuntimeException('Sender is not registered in Senders & Teams. Transaction rejected.');
        }

        $options = [];
        foreach ($rows as $sender) {
            $options[] = [
                'id' => $sender['id'],
                'display_name' => $sender['display_name'],
                'alias' => $sender['alias'],
                'location' => $sender['location'],
                'team' => $sender['team'],
            ];
        }

        return [
            'sender_name' => $rows[0]['display_name'],
            'requires_selection' => count($rows) > 1,
            'selected_id' => count($rows) === 1 ? $rows[0]['id'] : null,
            'options' => $options,
        ];
    }

    /** @return list<array{id:int,display_name:string,alias:string,location:string,team:string,tracking_start_week:string}> */
    private function findActiveSenderCandidatesByNormalizedName(string $normalized): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, display_name, alias, location, team, tracking_start_week
             FROM team_members
             WHERE is_active = 1 AND normalized_name = :normalized_name
             ORDER BY alias ASC, id ASC'
        );
        $statement->execute(['normalized_name' => $normalized]);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->validatedSenderRow($row);
            }
        }

        return $result;
    }

    public function findTeamBySender(string $senderName): string
    {
        return $this->findActiveSender($senderName)['team'];
    }

    /** @return list<array{id:int,display_name:string,alias:string,location:string,normalized_name:string,normalized_alias:string,team:string,is_active:int,tracking_start_week:string,created_at:string,updated_at:string}> */
    public function findAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, display_name, alias, location, normalized_name, normalized_alias, team, is_active, tracking_start_week, created_at, updated_at
             FROM team_members
             ORDER BY team ASC, display_name ASC, alias ASC, id ASC'
        );
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'alias' => (string) ($row['alias'] ?? ''),
                'location' => (string) ($row['location'] ?? ''),
                'normalized_name' => (string) ($row['normalized_name'] ?? ''),
                'normalized_alias' => (string) ($row['normalized_alias'] ?? ''),
                'team' => (string) ($row['team'] ?? ''),
                'is_active' => (int) ($row['is_active'] ?? 0),
                'tracking_start_week' => (string) ($row['tracking_start_week'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $result;
    }

    public function create(string $displayName, string $alias, string $location, string $team): int
    {
        $displayName = self::validatedName($displayName, 'Sender name');
        $alias = self::validatedAlias($alias);
        $location = self::validatedLocation($location);
        $normalized = self::normalizeName($displayName);
        $normalizedAlias = self::normalizeName($alias);
        $team = self::validatedTeam($team);

        $this->assertAliasAvailable($normalizedAlias, 0);

        $statement = $this->pdo->prepare(
            'INSERT INTO team_members (display_name, alias, location, normalized_name, normalized_alias, team, is_active, tracking_start_week)
             VALUES (:display_name, :alias, :location, :normalized_name, :normalized_alias, :team, 1, :tracking_start_week)'
        );
        $statement->execute([
            'display_name' => $displayName,
            'alias' => $alias,
            'location' => $location,
            'normalized_name' => $normalized,
            'normalized_alias' => $normalizedAlias,
            'team' => $team,
            'tracking_start_week' => $this->currentWeekStart(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $displayName, string $alias, string $location, string $team, bool $isActive): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid sender record.');
        }

        $displayName = self::validatedName($displayName, 'Sender name');
        $alias = self::validatedAlias($alias);
        $location = self::validatedLocation($location);
        $normalized = self::normalizeName($displayName);
        $normalizedAlias = self::normalizeName($alias);
        $team = self::validatedTeam($team);

        $this->assertAliasAvailable($normalizedAlias, $id);
        $existing = $this->findRecordById($id);
        if ($existing === null) {
            throw new RuntimeException('Sender record was not found.');
        }
        $trackingStartWeek = (string) ($existing['tracking_start_week'] ?? $this->currentWeekStart());
        if ((int) ($existing['is_active'] ?? 0) !== 1 && $isActive) {
            $trackingStartWeek = $this->currentWeekStart();
        }

        $statement = $this->pdo->prepare(
            'UPDATE team_members
             SET display_name = :display_name,
                 alias = :alias,
                 location = :location,
                 normalized_name = :normalized_name,
                 normalized_alias = :normalized_alias,
                 team = :team,
                 is_active = :is_active,
                 tracking_start_week = :tracking_start_week
             WHERE id = :id'
        );
        $statement->bindValue(':display_name', $displayName);
        $statement->bindValue(':alias', $alias);
        $statement->bindValue(':location', $location);
        $statement->bindValue(':normalized_name', $normalized);
        $statement->bindValue(':normalized_alias', $normalizedAlias);
        $statement->bindValue(':team', $team);
        $statement->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':tracking_start_week', $trackingStartWeek);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        if ($statement->rowCount() === 0 && !$this->exists($id)) {
            throw new RuntimeException('Sender record was not found.');
        }

        if ((int) ($existing['is_active'] ?? 0) === 1 && !$isActive) {
            $cleanup = $this->pdo->prepare(
                "DELETE FROM weekly_payment_obligations
                 WHERE team_member_id = :id
                   AND status = 'pending'
                   AND week_start >= :current_week"
            );
            $cleanup->bindValue(':id', $id, PDO::PARAM_INT);
            $cleanup->bindValue(':current_week', $this->currentWeekStart());
            $cleanup->execute();
        }
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid sender record.');
        }

        $guard = $this->pdo->prepare(
            "SELECT COUNT(*) FROM weekly_payment_obligations
             WHERE team_member_id = :id AND status IN ('pending', 'unpaid')"
        );
        $guard->bindValue(':id', $id, PDO::PARAM_INT);
        $guard->execute();
        if ((int) $guard->fetchColumn() > 0) {
            throw new RuntimeException('Sender has outstanding weekly obligations. Disable the sender or settle the obligations before delete.');
        }

        $statement = $this->pdo->prepare('DELETE FROM team_members WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Sender record was not found.');
        }
    }

    public static function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name));
        if (!is_string($name) || $name === '') {
            return '';
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($name, 'UTF-8');
        }

        return strtoupper($name);
    }

    private static function validatedName(string $name, string $label): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name));
        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }

        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length < 2 || $length > 100) {
            throw new InvalidArgumentException($label . ' must contain 2-100 characters.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException($label . ' contains invalid characters.');
        }

        return $name;
    }

    private static function validatedAlias(string $alias): string
    {
        return self::validatedName($alias, 'SUBID');
    }

    private static function validatedLocation(string $location): string
    {
        $location = preg_replace('/\s+/u', ' ', trim($location));
        if (!is_string($location) || $location === '') {
            throw new InvalidArgumentException('Location is required.');
        }

        $length = function_exists('mb_strlen') ? mb_strlen($location, 'UTF-8') : strlen($location);
        if ($length < 2 || $length > 120) {
            throw new InvalidArgumentException('Location must contain 2-120 characters.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $location) === 1) {
            throw new InvalidArgumentException('Location contains invalid characters.');
        }

        return $location;
    }

    private static function validatedTeam(string $team): string
    {
        $team = strtoupper(trim($team));
        if (!in_array($team, self::TEAMS, true)) {
            throw new InvalidArgumentException('Invalid team.');
        }

        return $team;
    }

    private function assertAliasAvailable(string $normalizedAlias, int $excludeId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM team_members
             WHERE id <> :exclude_id AND normalized_alias = :normalized_alias
             LIMIT 1'
        );
        $statement->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        $statement->bindValue(':normalized_alias', $normalizedAlias);
        $statement->execute();
        if (is_array($statement->fetch())) {
            throw new RuntimeException('SUBID is already registered. Use a unique SUBID.');
        }
    }

    /** @param array<string,mixed> $row @return array{id:int,display_name:string,alias:string,location:string,team:string,tracking_start_week:string} */
    private function validatedSenderRow(array $row): array
    {
        $team = (string) ($row['team'] ?? '');
        if (!in_array($team, self::TEAMS, true)) {
            throw new RuntimeException('Sender team is invalid. Transaction rejected.');
        }

        $alias = (string) ($row['alias'] ?? '');
        if ($alias === '') {
            throw new RuntimeException('Sender SUBID is missing. Transaction rejected.');
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'alias' => $alias,
            'location' => (string) ($row['location'] ?? ''),
            'team' => $team,
            'tracking_start_week' => (string) ($row['tracking_start_week'] ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    private function findRecordById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, is_active, tracking_start_week FROM team_members WHERE id = :id LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function currentWeekStart(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone($this->timezone));
        $day = (int) $now->format('N');

        return $now->modify('-' . (string) ($day - 1) . ' days')->format('Y-m-d');
    }

    private function exists(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM team_members WHERE id = :id LIMIT 1');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        return is_array($statement->fetch());
    }
}
