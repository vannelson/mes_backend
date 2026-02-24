<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class UserSeeder extends Seeder
{
    /**
     * Seed example application users.
     */
    public function run(): void
    {
        $filePath = base_path('Work Orders Jan to Nov 2025_Completed/staffcode.xlsx');

        if (! file_exists($filePath)) {
            $this->command?->warn("Staff code file not found at {$filePath}. Skipping user seeding.");

            return;
        }

        $rows = $this->loadRows($filePath);
        if (count($rows) < 2) {
            $this->command?->warn('Staff code sheet is empty. Skipping user seeding.');

            return;
        }

        $header = array_shift($rows);
        $headerMap = $this->buildHeaderMap($header);
        $codeColumn = $this->resolveHeaderColumn($headerMap, ['staff code', 'code', 'staffcode', 'staff id', 'staff_id']);
        $nameColumn = $this->resolveHeaderColumn($headerMap, ['staff name', 'name', 'staffname', 'staff full name', 'fullname']);

        if (! $codeColumn && ! $nameColumn) {
            $this->command?->warn('No staff code or staff name columns were detected. Skipping user seeding.');

            return;
        }

        $usedEmails = [];
        foreach (User::pluck('email')->all() as $email) {
            $usedEmails[strtolower($email)] = true;
        }

        foreach ($rows as $index => $row) {
            $staffCode = $this->stringValue($row[$codeColumn] ?? null);
            $staffName = $this->stringValue($row[$nameColumn] ?? null);

            if ($staffCode === null && $staffName === null) {
                continue;
            }

            $nameParts = $this->splitName($staffName);
            if ($nameParts['firstname'] === null || $nameParts['lastname'] === null) {
                $fallback = $staffCode ?? 'Operator';
                $nameParts = [
                    'firstname' => $fallback,
                    'middlename' => null,
                    'lastname' => $fallback,
                ];
            }

            $email = $this->buildUniqueEmail($staffCode, $staffName, $index + 2, $usedEmails);

            $payload = [
                'firstname' => $nameParts['firstname'],
                'lastname' => $nameParts['lastname'],
                'middlename' => $nameParts['middlename'],
                'position' => 'Operator',
                'address' => 'N/A',
                'user_type' => 'operator',
                'finger_print' => $staffCode,
                'email' => $email,
                'password' => Hash::make('12345678'),
            ];

            if ($staffCode !== null) {
                $payload['staff_code'] = $staffCode;
            }

            if ($staffCode !== null) {
                User::updateOrCreate(['staff_code' => $staffCode], $payload);
            } else {
                User::updateOrCreate(['email' => $email], $payload);
            }
        }
    }

    private function loadRows(string $filePath): array
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to load the staff code spreadsheet.', 0, $e);
        }

        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getSheet(0);

        return $worksheet->toArray(null, true, true, true);
    }

    private function buildHeaderMap(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $column => $label) {
            $label = $this->normalizeHeader($label);
            if ($label === '') {
                continue;
            }

            $map[$label] = $column;
        }

        return $map;
    }

    private function resolveHeaderColumn(array $headerMap, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $key = $this->normalizeHeader($candidate);
            if (isset($headerMap[$key])) {
                return $headerMap[$key];
            }
        }

        return null;
    }

    private function normalizeHeader(mixed $label): string
    {
        $label = strtolower(trim((string) $label));
        $label = preg_replace('/[^a-z0-9]+/', ' ', $label);

        return trim($label);
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function splitName(?string $name): array
    {
        $name = $this->stringValue($name);
        if ($name === null) {
            return [
                'firstname' => null,
                'middlename' => null,
                'lastname' => null,
            ];
        }

        if (str_contains($name, ',')) {
            [$last, $rest] = array_map('trim', explode(',', $name, 2));
            $restParts = preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY);
            $firstname = $restParts[0] ?? null;
            $middlename = count($restParts) > 1 ? implode(' ', array_slice($restParts, 1)) : null;

            return [
                'firstname' => $firstname,
                'middlename' => $middlename,
                'lastname' => $last !== '' ? $last : null,
            ];
        }

        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) === 1) {
            return [
                'firstname' => $parts[0],
                'middlename' => null,
                'lastname' => $parts[0],
            ];
        }

        if (count($parts) === 2) {
            return [
                'firstname' => $parts[0],
                'middlename' => null,
                'lastname' => $parts[1],
            ];
        }

        return [
            'firstname' => $parts[0],
            'middlename' => implode(' ', array_slice($parts, 1, -1)),
            'lastname' => $parts[count($parts) - 1],
        ];
    }

    private function buildUniqueEmail(?string $staffCode, ?string $staffName, int $rowNumber, array &$usedEmails): string
    {
        $domain = 'cclind.com';
        $local = null;

        if ($staffCode !== null && preg_match('/^[a-zA-Z0-9._%+\-]+$/', $staffCode)) {
            $local = strtolower($staffCode);
        }

        if ($local === null) {
            $base = $staffName ?? "operator{$rowNumber}";
            $local = strtolower(preg_replace('/[^a-z0-9]+/', '', $base));
            if ($local === '') {
                $local = "operator{$rowNumber}";
            }
        }

        $email = "{$local}@{$domain}";
        $suffix = 1;
        while (isset($usedEmails[strtolower($email)])) {
            $email = "{$local}+{$suffix}@{$domain}";
            $suffix++;
        }

        $usedEmails[strtolower($email)] = true;

        return $email;
    }
}
