<?php

namespace hypeJunction\Filestore;

use ElggEntity;
use ElggFile;
use WideImage\Exception\Exception;
use WideImage\WideImage;

/**
 * Handles entity icons
 *
 * @package    HypeJunction
 * @subpackage Filestore
 */
class IconHandler {

	/**
	 * List of croppabe icon sizes
	 * @static array
	 */
	static $croppable = array('topbar', 'tiny', 'small', 'medium', 'large');

	/**
	 * Create icons for an entity
	 *
	 * @param ElggFile $entity      An entity that will use the icons
	 * @param mixed    $source_file ElggFile, or remote path, or temp storage from where the source for the icons should be taken from
	 * @param array    $config      Additional parameters, such as 'icon_sizes', 'icon_filestore_prefix', 'coords'
	 * @uses array  $config['icon_sizes']            Additional icon sizes to create
	 * @uses string $config['icon_filestore_prefix'] Prefix of cropped/resizes icon sizes on the filestore
	 * @uses array  $config['coords']                Cropping coords
	 * @return array|boolean An array of filehandlers for created icons or false on error
	 */
	public static function makeIcons($entity, $source_file = null, array $config = array()) {

		if (!elgg_instanceof($entity)) {
			return false;
		}

		if ($source_file instanceof ElggFile) {
			$source = $source_file->getFilenameOnFilestore();
		} else if ($source_file) {
			$source = $source_file;
		} else if ($entity instanceof ElggFile) {
			$source = $entity->getFilenameOnFilestore();
		}

		if (!$source) {
			return false;
		}

		if (isset($config['icon_sizes'])) {
			$icon_sizes = $config['icon_sizes'];
		}

		$icon_sizes = self::getIconSizes($entity, $icon_sizes);
		$coords = elgg_extract('coords', $config, null);

		if (!isset($config['icon_filestore_prefix'])) {
			if (elgg_instanceof($entity, 'user')) {
				$prefix = "profile/";
			} else if (elgg_instanceof($entity, 'group')) {
				$prefix = "groups/";
			} else {
				$prefix = "icons/";
			}
		}
		$prefix .= $entity->getGUID();

		$img = WideImage::load($source);

		$thumb_m = elgg_extract('master', $icon_sizes, array(
			'w' => 550,
			'h' => 550
		));

		foreach ($icon_sizes as $size => $thumb) {

			try {

				if (is_array($coords) && (in_array($size, self::$croppable) || elgg_extract('croppable', $thumb, false))) {
					$resized = $img->resize($thumb_m['w'], $thumb_m['h'], 'inside', 'down');
					$resized = $resized->crop($coords['x1'], $coords['y1'], $coords['x2'] - $coords['x1'], $coords['y2'] - $coords['y1']);
				} else {
					$resized = $img;
				}

				if (in_array($size, self::$croppable) || elgg_extract('croppable', $thumb, false)) {
					$resized = $resized->resize(elgg_extract('w', $thumb, null), elgg_extract('h', $thumb, null), 'outside', 'any');
					$resized = $resized->crop('center', 'center', elgg_extract('w', $thumb, null), elgg_extract('h', $thumb, null));
				} else if (!is_array($coords)) {
					$resized = $resized->resize(elgg_extract('w', $thumb, null), elgg_extract('h', $thumb, null), 'inside', 'down');
				} else {
					continue;
				}

				switch ($entity->mimetype) {
					default :
					case 'image/jpeg' :
						$mime = 'image/jpeg';
						$contents = $resized->asString('jpg', 80);
						$filename = $prefix . $size . ".jpg";
						break;

					case 'image/gif' :
						$mime = 'image/gif';
						$filename = $prefix . $size . ".gif";
						$contents = $resized->asString('gif');
						break;

					case 'image/png' :
						$mime = 'image/png';
						$contents = $resized->asString('png');
						$filename = $prefix . $size . ".png";
						break;
				}

				$new_thumb = new ElggFile();
				if (elgg_instanceof($entity, 'user')) {
					$new_thumb->owner_guid = $entity->guid;
				} else {
					$new_thumb->owner_guid = $entity->owner_guid;
				}
				$new_thumb->setFilename($filename);
				$new_thumb->open('write');
				$new_thumb->write($contents);
				$new_thumb->close();

				if (isset($thumb['metadata_name'])) {
					$metadata_name = $thumb['metadata_name'];
					$entity->$metadata_name = $new_thumb->getFilename();
				}
			} catch (Exception $e) {
				elgg_log($e->getMessage(), 'ERROR');
				$error = true;
			}
		}

		if (!$error) {
			if (elgg_instanceof($entity, 'group')) {
				$filehandler = new ElggFile();
				$filehandler->owner_guid = $entity->owner_guid;
				$filehandler->setFilename("groups/{$entity->guid}.jpg");
				$filehandler->open("write");
				$filehandler->close();
				move_uploaded_file($source_file, $filehandler->getFilenameOnFilestore());
			}

			if (is_array($coords)) {
				foreach ($coords as $coord => $value) {
					$entity->$coord = $value;
				}
			} else {
				foreach (array('x1', 'x2', 'y1', 'y2') as $coord) {
					$entity->$coord = 0;
				}
			}
			$entity->icontime = time();
			return true;
		}

		return false;
	}

	/**
	 * Get icon size config
	 *
	 * @param ElggEntity $entity     Entity
	 * @param array      $icon_sizes An array of predefined icon sizes
	 * @return array
	 */
	protected static function getIconSizes($entity, $icon_sizes = array()) {

		$type = $entity->getType();
		$subtype = $entity->getSubtype();

		if ($subtype == 'file') {
			$defaults = array(
				'thumb' => array(
					'w' => 60,
					'h' => 60,
					'square' => true,
					'upscale' => true,
					'metadata_name' => 'thumbnail',
				),
				'smallthumb' => array(
					'w' => 153,
					'h' => 153,
					'square' => true,
					'upscale' => true,
					'metadata_name' => 'smallthumb',
				),
				'largethumb' => array(
					'w' => 600,
					'h' => 600,
					'square' => true,
					'upscacle' => true,
					'metadata_name' => 'largethumb',
				)
			);
		} else {
			$defaults = elgg_get_config('icon_sizes');
		}

		if (is_array($icon_sizes)) {
			$icon_sizes = array_merge($defaults, $icon_sizes);
		} else {
			$icon_sizes = $defaults;
		}

		return elgg_trigger_plugin_hook('entity:icon:sizes', $type, array(
			'entity' => $entity,
			'subtype' => $subtype,
				), $icon_sizes);
	}

	/**
	 * Outputs raw icon
	 *
	 * @param int    $entity_guid GUID of an entity
	 * @param string $size        Icon size
	 * @return void
	 */
	public static function outputRawIcon($entity_guid = 0, $size = null) {

		if (headers_sent()) {
			exit;
		}

		$ha = access_get_show_hidden_status();
		access_show_hidden_entities(true);

		$entity_guid = ($entity_guid) ?: get_input('guid');
		$entity = get_entity($entity_guid);

		if (!$entity) {
			exit;
		}

		$size = ($size) ?: get_input('size', 'medium');
		$size = strtolower($size);

		$filename = "icons/" . $entity->guid . $size . ".jpg";
		$etag = md5($entity->icontime . $size);

		$filehandler = new ElggFile();
		$filehandler->owner_guid = $entity->owner_guid;
		$filehandler->setFilename($filename);
		if ($filehandler->exists()) {
			$filehandler->open('read');
			$contents = $filehandler->grabFile();
			$filehandler->close();
		} else {
			exit;
		}

		$mimetype = ($entity->mimetype) ? $entity->mimetype : 'image/jpeg';

		access_show_hidden_entities($ha);

		header("Content-type: $mimetype");
		header("Etag: $etag");
		header('Expires: ' . date('r', time() + 864000));
		header("Pragma: public");
		header("Cache-Control: public");
		header("Content-Length: " . strlen($contents));

		echo $contents;
		exit;
	}

}
