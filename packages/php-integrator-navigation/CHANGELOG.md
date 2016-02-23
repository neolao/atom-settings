## 0.5.0 (base 0.6.0)
* The dependency on fuzzaldrin was removed.
* Fixed class constants being underlined as if no navigation was possible, while it was.
* It is now possible to alt-click built-in functions and classes to navigate to the PHP documentation in your browser.

## 0.4.0 (base 0.5.0)
* The modifier keys that are used in combination with a mouse click are now modifiable as settings.
* Show a dashed line if an item is recognized, but navigation is not possible (i.e. because the item wasn't found).

## 0.3.0 (base 0.4.0)
* Added navigation to the definition of global constants.
* Fixed navigation not working in corner cases where a property and method existed with the same name.

## 0.2.4
* Don't try to navigate to items that don't have a filename set. Fixes trying to alt-click internal classes such as 'DateTime' opening an empty file.

## 0.2.3
* Fixed markers not always registering on startup because the language-php package was not yet ready.

## 0.2.2
* Simplified class navigation and fixed it not working in some rare cases.

## 0.2.1
* Stop using maintainHistory to be compatible with upcoming Atom 1.3.

## 0.2.0
* Added navigation to the definition of class constants.
* Added navigation to the definition of (user-defined) global functions.

## 0.1.0
* Initial release.
