<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

class WasmtimeBindings
{
    private static ?\FFI $ffi = null;
    private static ?string $libraryPath = null;

    public static function load(?string $libraryPath = null): void
    {
        if (self::$ffi !== null) {
            return;
        }

        $libraryPath = $libraryPath ?? WasmtimeConfig::findLibrary();
        self::$libraryPath = $libraryPath;

        $cDeclarations = WasmtimeConfig::buildFFIDeclarations();

        try {
            self::$ffi = \FFI::cdef($cDeclarations, $libraryPath);
        } catch (\FFI\ParserException $e) {
            throw new WasmtimeUnavailableException(
                'Failed to parse Wasmtime C declarations: ' . $e->getMessage()
            );
        } catch (\FFI\Exception $e) {
            throw new WasmtimeUnavailableException(
                'Failed to load Wasmtime library: ' . $e->getMessage()
            );
        }
    }

    public static function get(): \FFI
    {
        if (self::$ffi === null) {
            throw new WasmtimeUnavailableException('Wasmtime bindings not loaded. Call load() first.');
        }
        return self::$ffi;
    }

    public static function getLibraryPath(): string
    {
        if (self::$libraryPath === null) {
            throw new WasmtimeUnavailableException('Wasmtime not loaded.');
        }
        return self::$libraryPath;
    }

    public static function unload(): void
    {
        self::$ffi = null;
        self::$libraryPath = null;
    }

    public static function isLoaded(): bool
    {
        return self::$ffi !== null;
    }
}
