<?php

namespace WCM\AstroFields\Core\Mediators;

use WCM\AstroFields\Core\Commands\ContextAwareInterface;
use WCM\AstroFields\Core\Helpers\ContextParser;

class Entity implements \SplSubject
{
	/** @type string */
	private $key;

	/** @type Array */
	private $types;

	/** @type Array */
	private $proxy = array();

	/** @type \SplObjectstorage */
	private $commands;

	/**
	 * @param string $key
	 * @param array  $types
	 */
	public function __construct( $key, Array $types = array() )
	{
		$this->key   = $key;
		$this->types = $types;

		$this->commands  = new \SplObjectstorage;
	}

	public function getKey()
	{
		return $this->key;
	}

	public function getTypes()
	{
		return $this->types;
	}

	/**
	 * Attach an SplObserver
	 * If the context is empty, but ContextAwareInterface implemented,
	 * the context was deliberately emptied to allow manual triggering from
	 * i.e. a Meta Box, an users profile, a custom form, etc.
	 * @param \SplObserver $command
	 * @param array        $info
	 * @return $this
	 */
	public function attach( \SplObserver $command, Array $info = array() )
	{
		# @TODO Fix `type` vs. `types` to the latter here and in all dependencies.
		$data = $info + array(
			'key'  => $this->key,
			'type' => $this->types,
		);

		if (
			$command instanceof ContextAwareInterface
			AND '' !== $command->getContext()
			)
		{
			$command->setContext( $this->parseContext(
				$command->getContext(),
				$data
			) );

			# @TODO Rethink if we can somehow still add the Command to the \SplObjectstorage
			# without running into the problem that we would manually trigger it a second time.
			$this->dispatch( $command, $data );

			return $this;
		}

		$this->commands->attach( $command, $data );

		return $this;
	}

	/**
	 * Attach placeholders for the context
	 * @param array $proxy
	 * @return $this
	 */
	public function setProxy( Array $proxy )
	{
		$this->proxy = $proxy;

		return $this;
	}

	/**
	 * Build the context (hooks/filters) array
	 * When a context is provided when attaching a Command,
	 * you can use `{key}` and `{type}` as placeholder.
	 * @param  string $context
	 * @param  array  $info
	 * @return array
	 */
	protected function parseContext( $context, Array $info = array() )
	{
		$input = array_filter( array(
			'{key}'   => array( $this->key ),
			'{type}'  => $this->types,
			'{proxy}' => $this->proxy,
		) );

		$parser = new ContextParser( $input, $context );
		return $parser->getResult();
	}

	/**
	 * Build the final array of Contexts
	 * @author Jon
	 * @link http://stackoverflow.com/a/6313346/376483
	 * @param array $input
	 * @return array
	 */
	public function cartesian( Array $input )
	{
		$result = array();

		while ( list( $key, $values ) = each( $input ) )
		{
			if ( empty( $values ) )
				continue;

			if ( empty( $result ) )
			{
				foreach ( $values as $value )
					$result[] = array( $key => $value );
			}
			else
			{
				$append = array();

				foreach ( $result as &$product )
				{
					$product[ $key ] = array_shift( $values );
					$copy = $product;

					foreach ( $values as $item )
					{
						$copy[ $key ] = $item;
						$append[] = $copy;
					}

					array_unshift( $values, $product[ $key ] );
				}

				$result = array_merge( $result, $append );
			}
		}

		return $result;
	}

	/**
	 * Detach an Observer/a Command from the stack
	 * @param \SplObserver $command
	 * @return $this
	 */
	public function detach( \SplObserver $command )
	{
		$this->commands->detach( $command );

		# @TODO Remove from filter callback stack
		# foreach ( $command->getContext() as $c )
		# remove_filter( $c, array( $command, 'update' ) );

		return $this;
	}

	/**
	 * Retrieve all attached Commands
	 * @return \SplObjectstorage
	 */
	public function getCommands()
	{
		$commands = clone $this->commands;
		$commands->rewind();

		return $commands;
	}

	/**
	 * Notify all attached Commands to execute
	 */
	public function notify()
	{
		$this->commands->rewind();
		foreach ( $this->commands as $o )
		{
			$this->commands->current()->update(
				$this,
				$this->commands->getInfo()
			);
		}
	}

	/**
	 * Delay the execution of a Command until the appearance of a hook or filter
	 * $subject = $this Alias:
	 * PHP 5.3 fix, as Closures don't know where to point $this prior to 5.4
	 * props Malte "s1lv3r" Witt
	 * @link https://wiki.php.net/rfc/closures/object-extension
	 * @param \SplObserver|ContextAwareInterface $command
	 * @param array                              $data
	 */
	public function dispatch( ContextAwareInterface $command, Array $data )
	{
		$contexts = $command->getContext();
		$subject  = $this;

		foreach ( $contexts as $context )
		{
			/** @type $command */
			add_filter( $context, function() use ( $subject, $command, $data, $context )
			{
				// Provide all filter arguments to the Command
				$data['args'] = func_get_args();

				return $command->update(
					$subject,
					$data
				);

			}, 10, PHP_INT_MAX -1 );
		}
	}
}