<?php

/*
 * LicenseNoticeTest.php
 * Copyright (c) 2026 fork contributors
 *
 * This file is part of the Firefly III Data Importer Akahu fork
 * (https://github.com/bedesv/data-importer).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class LicenseNoticeTest extends TestCase
{
    public function testNoticeLinksToExactDeployedSourceRevision(): void
    {
        config()->set('importer.source_revision', '0123456789abcdef');

        $html = view('layout.legal')->render();

        self::assertStringContainsString(
            'href="https://github.com/bedesv/data-importer/tree/0123456789abcdef"',
            $html
        );
        self::assertStringContainsString(
            'href="https://github.com/bedesv/data-importer/blob/0123456789abcdef/LICENSE"',
            $html
        );
        self::assertStringContainsString('Download the corresponding source code', $html);
        self::assertStringContainsString('Distributed without warranty.', $html);
    }

    public function testNoticeFallsBackToPublicDevelopmentSource(): void
    {
        config()->set('importer.source_revision', '');

        $html = view('layout.legal')->render();

        self::assertStringContainsString(
            'href="https://github.com/bedesv/data-importer"',
            $html
        );
        self::assertStringContainsString(
            'href="https://github.com/bedesv/data-importer/blob/dev/LICENSE"',
            $html
        );
    }
}
