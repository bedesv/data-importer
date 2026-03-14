<?php

declare(strict_types=1);

/*
 * CollectsSettings.php
 * Copyright (c) 2025 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Support\Http\Upload;

trait CollectsSettings
{
    protected function getSimpleFINSettings(): array
    {
        return ['token' => old('simplefin_token') ?? config('simplefin.token')];
    }

    protected function getAkahuSettings(): array
    {
        return [
            'app_token'                => old('akahu_app_token') ?? config('akahu.app_token'),
            'user_token'               => old('akahu_user_token') ?? config('akahu.user_token'),
            'internal_account_prefix'  => old('akahu_internal_account_prefix') ?? config('akahu.internal_account_prefix'),
            'mortgage_payment_pattern' => old('akahu_mortgage_payment_pattern') ?? config('akahu.mortgage_payment_pattern'),
        ];
    }
}
