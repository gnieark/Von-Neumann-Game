<?php

declare(strict_types=1);

/** Token-based boundary checks; comments and method names inside strings are ignored. */
final class PersistenceArchitecture
{
    public static function violations(string $source, bool $repository): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $text = static fn($token): string => is_array($token) ? $token[1] : $token;
        $errors = [];
        $visibility = 'public';
        $method = null;
        $parameters = [];
        $publicMethod = false;
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $value = $text($token);
            $kind = is_array($token) ? $token[0] : null;
            if (!$repository) {
                if (in_array($kind, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/(?:^|\\\\)(?:PDO|PDOStatement|PDOException|mysqli|SQLite3)$/i', $value)) { $errors[] = 'SQL dependency: ' . $value; }
                if (in_array($kind, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true) && in_array(strtolower($text($tokens[$i + 1] ?? '')), ['prepare','query','exec','begintransaction','commit','rollback','pdo','lastinsertid'], true) && $text($tokens[$i + 2] ?? '') === '(') { $errors[] = 'Direct SQL/connection method: ' . $text($tokens[$i + 1]); }
                if (in_array($kind, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match('/^\s*(?:SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE)\b/is', trim($value, "\"'"))) { $errors[] = 'SQL statement in business code'; }
                continue;
            }
            if (in_array($kind, [T_PUBLIC,T_PROTECTED,T_PRIVATE], true)) { $visibility = strtolower($value); }
            if ($kind === T_FUNCTION && ($tokens[$i + 1][0] ?? null) === T_STRING) {
                $method = $text($tokens[$i + 1]);
                $publicMethod = $visibility !== 'private';
                $parameters = [];
                $j = $i + 2;
                while (isset($tokens[$j]) && $text($tokens[$j]) !== '(') { $j++; }
                $depth = 1;
                for ($j++; isset($tokens[$j]) && $depth > 0; $j++) {
                    $part = $text($tokens[$j]);
                    if ($part === '(') { $depth++; }
                    if ($part === ')') { $depth--; }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) { $parameters[] = $part; }
                }
                if ($publicMethod && $method !== '__construct') {
                    if (array_intersect($parameters, ['$sql','$query','$statement']) !== []) { $errors[] = 'Arbitrary SQL parameter: ' . $method; }
                    while (isset($tokens[$j]) && !in_array($text($tokens[$j]), ['{',';'], true)) {
                        if (preg_match('/(?:^|\\\\)PDO(?:Statement)?$/i', $text($tokens[$j]))) { $errors[] = 'Connection return type: ' . $method; }
                        $j++;
                    }
                }
                $visibility = 'public';
            }
            if ($publicMethod && $method !== '__construct' && $kind === T_OBJECT_OPERATOR && in_array(strtolower($text($tokens[$i + 1] ?? '')), ['prepare','query','exec'], true) && in_array($text($tokens[$i + 3] ?? ''), $parameters, true)) { $errors[] = 'Caller supplies SQL to: ' . $method; }
            if ($publicMethod && $kind === T_RETURN && $text($tokens[$i + 1] ?? '') === '$this' && $text($tokens[$i + 2] ?? '') === '->' && in_array(strtolower($text($tokens[$i + 3] ?? '')), ['pdo','connection'], true) && $text($tokens[$i + 4] ?? '') === ';') { $errors[] = 'Connection exposure: ' . $method; }
        }
        return array_values(array_unique($errors));
    }

    public static function audit(string $root): array
    {
        // Closed, role-specific debt. No directory is exempted.
        $migrations = ['src/Service/SectorStorageMigration.php', 'src/Service/DetachedContainerJsonMigrationService.php'];
        $frozenDebt = [
            'src/Service/UniverseStatsService.php' => '6a3ba5fbcd137ada39360be95355e9ed2113ef16037aa8de530cb9a02590d2a0',
            'src/Service/AsteroidTrajectory/AsteroidTrajectoryService.php' => '22efb62edfdc35a8e356f2c8fd0a8e6d6dbef51571c007fdcd35d24f232fa40c',
        ];
        $errors = [];
        foreach (['src/Service', 'src/Http', 'src/Repository'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') { continue; }
                $path = substr($file->getPathname(), strlen($root) + 1);
                if (in_array($path, $migrations, true)) { continue; }
                if (isset($frozenDebt[$path])) {
                    if (hash_file('sha256', $file->getPathname()) !== $frozenDebt[$path]) { $errors[] = $path . ': migrate the frozen SQL dependency before changing this debt component'; }
                    continue;
                }
                foreach (self::violations(file_get_contents($file->getPathname()), $directory === 'src/Repository') as $error) { $errors[] = $path . ': ' . $error; }
            }
        }
        return $errors;
    }
}
