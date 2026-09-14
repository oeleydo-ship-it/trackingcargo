<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CustomerType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;

final readonly class CustomerImportService
{
    private const array COLUMNS = ['name', 'type', 'company_name', 'email', 'phone', 'tax_id', 'identification_number'];

    public function __construct(private CustomerService $customers) {}

    /**
     * @return array{created: int, errors: array<int, list<string>>}
     */
    public function import(UploadedFile $file, User $actor): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['created' => 0, 'errors' => [1 => ['The file could not be read.']]];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['created' => 0, 'errors' => [1 => ['The file is empty.']]];
        }

        $header = array_map(static fn (string $column): string => trim(strtolower($column)), $header);

        $created = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, static fn ($value): bool => $value !== null && trim((string) $value) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $data = array_intersect_key($data, array_flip(self::COLUMNS));

            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', new Enum(CustomerType::class)],
                'company_name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'tax_id' => ['nullable', 'string', 'max:60'],
                'identification_number' => ['nullable', 'string', 'max:60'],
            ]);

            if ($validator->fails()) {
                $errors[$rowNumber] = $validator->errors()->all();

                continue;
            }

            $this->customers->create($validator->validated(), $actor);
            $created++;
        }

        fclose($handle);

        return ['created' => $created, 'errors' => $errors];
    }
}
