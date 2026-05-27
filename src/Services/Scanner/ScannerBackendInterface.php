<?php

namespace OzanKurt\Shield\Services\Scanner;

interface ScannerBackendInterface
{
    /** Machine name matching ls_scanner_backends.name */
    public function name(): string;

    /** Whether the backend is currently available/configured */
    public function isAvailable(): bool;

    /** True if this backend scans one file at a time */
    public function isPerFile(): bool;

    /**
     * Scan a single file. Only called when isPerFile() === true.
     *
     * @return array<int, array{
     *   signature_id: int|null,
     *   signature_ref: string|null,
     *   severity: string,
     *   matched_content: string|null,
     *   line_number: int|null,
     * }>
     */
    public function scanFile(string $path): array;

    /**
     * Run a whole-run scan. Only called when isPerFile() === false.
     *
     * @return array<int, array{
     *   file_path: string,
     *   signature_id: int|null,
     *   signature_ref: string|null,
     *   severity: string,
     *   matched_content: string|null,
     * }>
     */
    public function scanRun(): array;
}
