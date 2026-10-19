<?php

// Rebuild the moz-extensions library map, which `arc liberate` can not do;
// see `MozExtensionsLibraryMapBuilder`. Loading the builder directly means
// this works even when the map is too stale to autoload it.

require_once '/app/arcanist/support/init/init-script.php';

$root = '/src/moz-extensions/src';
require_once $root.'/liberate/MozExtensionsLibraryMapBuilder.php';

id(new MozExtensionsLibraryMapBuilder($root))->buildAndWriteMap();

echo "Updated library: moz-extensions/src/\n";
