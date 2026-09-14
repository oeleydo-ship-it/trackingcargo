<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use App\Enums\DocumentCategory;
use App\Models\CustomsClearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CustomsClearance $clearance */
        $clearance = $this->route('clearance');

        return $this->user()?->can('update', $clearance) ?? false;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', new Enum(DocumentCategory::class)],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ];
    }
}
