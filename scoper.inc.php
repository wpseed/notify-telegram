<?php
/**
 * PHP-Scoper configuration.
 *
 * Everything the plugin ships is copied to `dist/<slug>/` with its namespaces prefixed as
 * `NotifyTelegram\Dependencies\…`, so a shared library (Guzzle, Symfony, …) cannot collide
 * with the copy of it that another active plugin loaded first.
 *
 * The plugin's own code in the `Wpseed\NotifyTelegram` namespace keeps its names: only the
 * dependencies are isolated, and references to them from the plugin are rewritten
 * automatically. `composer build` runs the whole archive build (see the README).
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

return array(
	'prefix'             => 'NotifyTelegram\\Dependencies',

	/*
	 * Symbols declared in these namespaces are left untouched (the plugin's own code and
	 * its tests). Sub-namespaces are covered too, so `Wpseed\NotifyTelegram\Tests` is included.
	 */
	'exclude-namespaces' => array(
		'Wpseed\\NotifyTelegram',
	),

	/*
	 * Dependencies that use WordPress or PHPUnit symbols need those excluded as well, e.g.
	 * 'exclude-classes' => array( 'WP_List_Table' ), 'exclude-functions' => array( 'apply_filters' ),
	 * 'exclude-constants' => array( 'WP_PLUGIN_DIR' ). Add them per dependency, not preemptively:
	 * an excluded symbol is a symbol that can still collide.
	 */
);
