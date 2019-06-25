<?php

namespace common\php;

use \common\php\Util;
use \common\php\Slide;
use \common\php\User;
use \common\php\Exportable;
use \common\php\JSONUtils;
use \common\php\JSONException;
use \common\php\Exceptions\ArgException;
use \common\php\Exceptions\IntException;

/**
* Queue class for handling LibreSignage queue data.
*/
final class Queue extends Exportable {
	static $PRIVATE = [
		'name',
		'owner',
		'slides'
	];

	static $PUBLIC = [
		'owner',
		'slides'
	];

	const NAME_REGEX = '/^[A-Za-z0-9_-]+$/';

	private $name   = NULL;
	private $owner  = NULL;
	private $slides = NULL;
	private $loaded = FALSE;

	public function __exportable_set(string $name, $value) {
		$this->{$name} = $value;
	}

	public function __exportable_get(string $name) {
		return $this->{$name};
	}

	function __construct(string $name) {
		$this->set_name($name);
		$this->slides = [];
	}

	/*
	* Load a queue from file. If $fix_errors === TRUE, slides
	* that can't be loaded are automatically removed from the queue.
	* The changes to the queue are automatically written to file.
	*
	* @param bool $fix_errors If TRUE, invalid slides are removed
	*                         from the loaded queue.
	* @throws ArgException if the queue $name doesn't exist.
	*/
	public function load(bool $fix_errors = FALSE) {
		$errors_fixed = FALSE;

		if (!file_exists($this->get_path())) {
			throw new ArgException("Queue doesn't exist.");
		}

		$json = Util::file_lock_and_get($this->get_path());
		$data = JSONUtils::decode($json);

		$this->set_owner($data->owner);

		$this->slides = [];
		foreach ($data->slides as $n) {
			$tmp = new Slide();
			try {
				$tmp->load($n);
			} catch (\Exception $e) {
				if (
					!(
						$e instanceof ArgException
						|| $e instanceof IntException
					)
					|| $fix_errors === FALSE
				) {
					throw $e;
				} else {
					$errors_fixed = TRUE;
					continue;
				}
			}
			$this->slides[] = $tmp;
		}

		// Write changes to disk in case any errors were fixed.
		if ($errors_fixed === TRUE) { $this->write(); }

		$this->loaded = TRUE;
	}

	/**
	* Write a queue to file.
	*
	* @throws ArgException if the queue doesn't have an owner.
	*/
	public function write() {
		if (!$this->owner) {
			throw new ArgException("Queue doesn't have an owner.");
		}
		$json = JSONUtils::encode($this->export(TRUE, TRUE));
		Util::file_lock_and_put($this->get_path(), $json);
	}

	/**
	* Recalculate slide indices so that no unused indices
	* remain between slides and sort the slide array.
	*/
	public function normalize() {
		usort($this->slides, function(Slide $a, Slide $b) {
			if ($a->get_index() > $b->get_index()) {
				return 1;
			} else if ($a->get_index() < $b->get_index()) {
				return -1;
			} else {
				return 0;
			}
		});
		for ($i = 0; $i < count($this->slides); $i++) {
			$this->slides[$i]->set_index($i);
			$this->slides[$i]->write();
		}
	}

	/**
	* Recalculate slide indices so that the position of the slide with
	* the ID $keep_id stays the same, no unused indices remain and slides
	* are sorted based on the indices.
	*
	* @param string $keep_id The ID of the slide to keep at it's position.
	* @throws ArgException if the slide $keep_id doesn't exist in the queue.
	*/
	public function juggle(string $keep_id) {
		$keep = NULL;
		$clash = FALSE;

		// Remove the slide with ID $keep_id initially.
		foreach ($this->slides as $k => $s) {
			if ($s->get_id() == $keep_id) {
				$keep = $s;
				unset($this->slides[$k]);
				$this->slides = array_values($this->slides);
				break;
			}
		}

		if (!$keep) {
			throw new ArgException("Slide $keep_id doesn't exist in queue.");
		}
		$this->normalize();

		// Shift indices so that the index of $keep_id is left free.
		$keep_i = $keep->get_index();
		foreach ($this->slides as $k => $s) {
			$s_i = $s->get_index();
			$clash |= $s_i == $keep_i;
			if ($s_i >= $keep_i) {
				$s->set_index($s_i + 1);
				$s->write();
			}
		}
		if (!$clash) {
			/*
			* $keep_id didn't have the same index as any of the
			* other slides -> make it the last one.
			*/
			$keep->set_index(count($this->slides));
			$keep->write();
		}

		// Add $keep back to $this->slides at the correct index.
		$this->slides[] = $keep;
		$this->normalize();
	}

	/**
	* Remove the loaded queue.
	*
	* @throws IntException if no queue is loaded.
	* @throws ArgException if the loaded queue doesn't exist anymore.
	* @throws IntException if unlink() fails.
	*/
	function remove() {
		assert($this->loaded, IntException('Queue not loaded.'));

		if (!file_exists($this->get_path())) {
			throw new ArgException("Queue doesn't exist.");
		}

		foreach ($this->slides() as $s) { $s->remove(); }

		if (!unlink($this->get_path())) {
			throw new IntException("Failed to remove queue.");
		}
	}

	/**
	* Validate $name to make sure it's a valid queue name.
	*
	* @param string $name The queue name to validate.
	* @throws ArgException if the queue name is not valid.
	* @throws IntException if preg_match() fails.
	*/
	public static function validate_name(string $name) {
		if (strlen($name) === 0) {
			throw new ArgException('Invalid empty queue name.');
		} else if (strlen($name) > Config::limit('QUEUE_NAME_MAX_LEN')) {
			throw new ArgException('Queue name too long.');
		}

		$tmp = preg_match(Queue::NAME_REGEX, $name);
		if ($tmp === 0) {
			throw new ArgException('Queue name contains invalid characters.');
		} else if ($tmp === FALSE) {
			throw new IntException('preg_match() failed.');
		}
	}

	public function set_name(string $name) {
		Queue::validate_name($name);
		$this->name = $name;
	}

	public function set_owner(string $owner) {
		User::validate_name($owner);
		$this->owner = $owner;
	}

	public function get_path(): string {
		return LIBRESIGNAGE_ROOT.QUEUES_DIR.'/'.$this->name.'.json';
	}

	public function get_owner(): string {
		return $this->owner;
	}

	/**
	* Add a slide to the loaded queue.
	*
	* @param Slide $slide The slide object to add.
	*/
	public function add(Slide $slide) {
		$this->slides[] = $slide;
	}

	/**
	* Remove a slide from the loaded queue.
	*
	* @param Slide $slide The slide object to remove.
	*/
	public function remove_slide(Slide $slide) {
		$this->slides = array_filter(
			$this->slides,
			function($s) use ($slide) {
				return $s->get_id() != $slide->get_id();
			}
		);
	}

	/**
	* Get the slides array of the loaded queue.
	*
	* @return array An array of Slide objects.
	*/
	public function slides(): array {
		return $this->slides;
	}

	/**
	* Get a Slide by ID.
	*
	* @param string $id The ID of the Slide to get.
	* @return Slide|NULL The Slide with ID $id or NULL if
	*                    no matching Slide exists.
	*/
	public function get_slide(string $id) {
		foreach($this->slides as $s) {
			if ($s->get_id() === $id) { return $s; }
		}
		return NULL;
	}

	/**
	* Check whether the queue with name $name exists.
	*
	* @param string $name The queue name.
	* @return bool TRUE if $name exists and FALSE otherwise.
	*/
	public static function exists(string $name): bool {
		return in_array($name, self::list());
	}

	/**
	* Get an array with all the existing queue names.
	*
	* @return array An array with all queue names.
	*/
	public static function list(): array {
		$queues = array_map(
			function(string $val) {
				if (
					substr($val, 0, 1) != '.'
					&& substr($val, -5) == '.json'
				) {
					return substr($val, 0, strlen($val) - 5);
				} else {
					return NULL;
				}
			},
			scandir(LIBRESIGNAGE_ROOT.QUEUES_DIR)
		);
		$queues = array_values(
			array_filter(
				$queues,
				function($val) {
					return $val != NULL;
				}
			)
		);
		return $queues;
	}
}
