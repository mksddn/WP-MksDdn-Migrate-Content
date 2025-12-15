<?php
/**
 * @file: ServiceContainer.php
 * @description: Simple dependency injection container for managing service dependencies
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple dependency injection container.
 *
 * @since 1.0.0
 */
class ServiceContainer {

	/**
	 * Registered services.
	 *
	 * @var array<string, array{factory: callable|string, singleton: bool, lazy: bool}>
	 */
	private array $services = array();

	/**
	 * Singleton instances cache.
	 *
	 * @var array<string, object>
	 */
	private array $singletons = array();

	/**
	 * Register a service in the container.
	 *
	 * @param string          $id        Service identifier.
	 * @param callable|string $factory   Factory callable or class name.
	 * @param bool            $singleton Whether to treat as singleton.
	 * @param bool            $lazy      Whether to lazy load (only instantiate when accessed).
	 * @return void
	 * @since 1.0.0
	 */
	public function register( string $id, callable|string $factory, bool $singleton = true, bool $lazy = false ): void {
		$this->services[ $id ] = array(
			'factory'   => $factory,
			'singleton' => $singleton,
			'lazy'      => $lazy,
		);
	}

	/**
	 * Get a service from the container.
	 *
	 * @param string $id Service identifier.
	 * @return mixed Service instance.
	 * @throws \RuntimeException If service not found.
	 * @since 1.0.0
	 */
	public function get( string $id ): mixed {
		// Return singleton if already instantiated.
		if ( isset( $this->singletons[ $id ] ) ) {
			return $this->singletons[ $id ];
		}

		// Check if service is registered.
		if ( ! isset( $this->services[ $id ] ) ) {
			throw new \RuntimeException( "Service '{$id}' is not registered in the container." );
		}

		$service_config = $this->services[ $id ];
		$factory        = $service_config['factory'];
		$is_singleton   = $service_config['singleton'];

		// Resolve service instance (lazy loading is automatic - services are only instantiated when get() is called).
		$instance = $this->resolve( $factory );

		// Cache singleton instances.
		if ( $is_singleton ) {
			$this->singletons[ $id ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if a service is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool True if registered, false otherwise.
	 * @since 1.0.0
	 */
	public function has( string $id ): bool {
		return isset( $this->services[ $id ] );
	}

	/**
	 * Resolve service instance from factory.
	 *
	 * @param callable|string $factory Factory callable or class name.
	 * @return mixed Resolved instance.
	 * @since 1.0.0
	 */
	private function resolve( callable|string $factory ): mixed {
		if ( is_callable( $factory ) ) {
			return call_user_func( $factory, $this );
		}

		if ( is_string( $factory ) && class_exists( $factory ) ) {
			return $this->resolve_class( $factory );
		}

		throw new \RuntimeException( 'Invalid factory provided for service resolution.' );
	}

	/**
	 * Resolve class instance with automatic dependency injection.
	 *
	 * @param string $class_name Class name.
	 * @return object Class instance.
	 * @since 1.0.0
	 */
	private function resolve_class( string $class_name ): object {
		$reflection = new \ReflectionClass( $class_name );

		// If no constructor, instantiate directly.
		if ( ! $reflection->hasMethod( '__construct' ) ) {
			return new $class_name();
		}

		$constructor = $reflection->getMethod( '__construct' );
		$parameters  = $constructor->getParameters();

		// If constructor has no parameters, instantiate directly.
		if ( empty( $parameters ) ) {
			return new $class_name();
		}

		// Resolve constructor parameters.
		$args = array();
		foreach ( $parameters as $param ) {
			$type = $param->getType();

			if ( ! $type instanceof \ReflectionNamedType ) {
				// Skip parameters without type hints or with union types.
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}

			$type_name = $type->getName();

			// Try to resolve from container by type name.
			if ( ! $type->isBuiltin() && $this->has( $type_name ) ) {
				$args[] = $this->get( $type_name );
				continue;
			}

			// Use default value if available.
			if ( $param->isDefaultValueAvailable() ) {
				$args[] = $param->getDefaultValue();
				continue;
			}

			// If nullable, pass null.
			if ( $type->allowsNull() ) {
				$args[] = null;
				continue;
			}

			// Cannot resolve, pass null (will use default in constructor).
			$args[] = null;
		}

		return $reflection->newInstanceArgs( $args );
	}

	/**
	 * Clear singleton cache (useful for testing).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function clear(): void {
		$this->singletons = array();
	}
}

