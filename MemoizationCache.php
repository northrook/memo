<?php

declare( strict_types = 1 );

namespace Northrook\Cache;

use Psr\Log as Psr;
use Symfony\Contracts\Cache as Symfony;
use const Duration\EPHEMERAL;

/**
 * Cache the result of a callable, improving  performance by avoiding redundant computations.
 *
 * It utilizes the {@see \Northrook\Cache\MemoizationCache} class,
 * which can either use an in-memory cache,  or a Symfony {@see Symfony\CacheInterface} if provided.
 *
 * @param \Closure  $callback     The function to cache
 * @param ?string   $key          [optional] key - a hash based on $callback and $arguments will be used if null
 * @param ?int      $persistence  The duration in seconds for the cache entry. Requires {@see Symfony\CacheInterface}.
 *
 * @return mixed
 */
function memoize(
    \Closure $callback,
    ?string  $key = null,
    ?int     $persistence = EPHEMERAL,
) : mixed {
    return MemoizationCache::instance()->cache( $callback, $key, $persistence );
}

final class MemoizationCache
{
    private static ?MemoizationCache $instance = null;

    private array $inMemoryCache;

    public function __construct(
        private readonly ?Symfony\CacheInterface $cacheAdapter = null,
        private readonly ?Psr\LoggerInterface    $logger = null,
    ) {
        $this::$instance = $this;
    }

    public function cache( \Closure $callback, ?string $key = null, ?int $persistence = EPHEMERAL ) : mixed {

        $key ??= $this->hash( $callback );

        if ( !$key ) {
            return $callback();
        }

        // If persistence is not requested, or if we are lacking a capable adapter
        if ( EPHEMERAL === $persistence || !$this->cacheAdapter ) {
            if ( !isset( $this->inMemoryCache[ $key ] ) ) {
                $this->inMemoryCache[ $key ] = [
                    'value' => $callback(),
                    'hit'   => 0,
                ];
            }
            else {
                $this->inMemoryCache[ $key ][ 'hit' ]++;
            }

            return $this->inMemoryCache[ $key ][ 'value' ];
        }

        try {
            return $this->cacheAdapter->get(
                key      : $key,
                callback : static function ( Symfony\ItemInterface $memo ) use ( $callback, $persistence ) : mixed {
                    $memo->expiresAfter( $persistence );
                    return $callback();
                },
            );
        }
        catch ( \Throwable $exception ) {
            $this->logger?->error(
                "Exception thrown when using {runtime}: {message}.",
                [
                    'runtime'   => $this::class,
                    'message'   => $exception->getMessage(),
                    'exception' => $exception,
                ],
            );
            return $callback();
        }
    }

    private function hash( \Closure $callback ) : ?string {
        try {
            $reflection = new \ReflectionFunction( $callback );
        }
        catch ( \ReflectionException $exception ) {
            $this->logger?->error(
                'Memo cache failed to perform reflection on passed Closure, the result has not been cached.',
                [ 'exception' => $exception, 'closure' => $callback ],
            );
            return null;
        }

        return \hash( 'xxh3', \serialize( $reflection->getClosureUsedVariables() ) );
    }

    public static function instance() : MemoizationCache {
        return MemoizationCache::$instance ?? new MemoizationCache();
    }

    /**
     * Clears the built-in memory cache.
     *
     * @return $this
     */
    public function clearInMemoryCache() : MemoizationCache {
        $this->inMemoryCache = [];
        return $this;
    }

    /**
     * Clears the {@see CacheInterface} if assigned.
     *
     * @return $this
     */
    public function clearAdapterCache() : MemoizationCache {
        $this->cacheAdapter?->clear();
        return $this;
    }
}