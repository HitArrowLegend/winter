<?php namespace Backend\Classes;

use Event;
use BackendAuth;
use System\Classes\PluginManager;
use Validator;
use SystemException;
use Log;
use Config;

/**
 * Manages the backend navigation.
 *
 * @package winter\wn-backend-module
 * @author Alexey Bobkov, Samuel Georges
 */
class NavigationManager
{
    use \Winter\Storm\Support\Traits\Singleton;

    /**
     * @var array Cache of registration callbacks.
     */
    protected $callbacks = [];

    /**
     * @var array List of owner aliases. ['Aliased.Owner' => 'Real.Owner']
     */
    protected $aliases = [];

    /**
     * @var MainMenuItem[] List of registered items.
     */
    protected $items;

    /**
     * @var QuickActionItem[] List of registered quick actions.
     */
    protected $quickActions;

    protected $contextSidenavPartials = [];

    protected $contextOwner;
    protected $contextMainMenuItemCode;
    protected $contextSideMenuItemCode;

    /**
     * @var PluginManager
     */
    protected $pluginManager;

    /**
     * Initialize this singleton.
     */
    protected function init()
    {
        $this->pluginManager = PluginManager::instance();
    }

    /**
     * Loads the menu items from modules and plugins
     * @return void
     * @throws SystemException
     */
    protected function loadItems()
    {
        $this->items = [];
        $this->quickActions = [];

        /*
         * Load module items
         */
        foreach ($this->callbacks as $callback) {
            $callback($this);
        }

        /*
         * Load plugin items
         */
        $plugins = $this->pluginManager->getPlugins();

        foreach ($plugins as $id => $plugin) {
            $items = $plugin->registerNavigation();
            $quickActions = $plugin->registerQuickActions();

            if (!is_array($items) && !is_array($quickActions)) {
                continue;
            }

            if (is_array($items)) {
                $this->registerMenuItems($id, $items);
            }
            if (is_array($quickActions)) {
                $this->registerQuickActions($id, $quickActions);
            }
        }

        /**
         * @event backend.menu.extendItems
         * Provides an opportunity to manipulate the backend navigation
         *
         * Example usage:
         *
         *     Event::listen('backend.menu.extendItems', function ((\Backend\Classes\NavigationManager) $navigationManager) {
         *         $navigationManager->addMainMenuItems(...)
         *         $navigationManager->addSideMenuItems(...)
         *         $navigationManager->removeMainMenuItem(...)
         *     });
         *
         */
        Event::fire('backend.menu.extendItems', [$this]);

        /*
         * Sort menu items and quick actions
         */
        uasort($this->items, static function ($a, $b) {
            return $a->order - $b->order;
        });
        uasort($this->quickActions, static function ($a, $b) {
            return $a->order - $b->order;
        });

        /*
         * Filter items and quick actions that the user lacks permission for
         */
        $user = BackendAuth::getUser();
        $this->items = $this->filterItemPermissions($user, $this->items);
        $this->quickActions = $this->filterItemPermissions($user, $this->quickActions);

        foreach ($this->items as $item) {
            if (!$item->sideMenu || !count($item->sideMenu)) {
                continue;
            }

            /*
             * Apply incremental default orders
             */
            $orderCount = 0;
            foreach ($item->sideMenu as $sideMenuItem) {
                if ($sideMenuItem->order !== -1) {
                    continue;
                }
                $sideMenuItem->order = ($orderCount += 100);
            }

            /*
             * Sort side menu items
             */
            uasort($item->sideMenu, static function ($a, $b) {
                return $a->order - $b->order;
            });

            /*
             * Filter items user lacks permission for
             */
            $item->sideMenu = $this->filterItemPermissions($user, $item->sideMenu);
        }
    }

    /**
     * Registers a callback function that defines menu items.
     * The callback function should register menu items by calling the manager's
     * `registerMenuItems` method. The manager instance is passed to the callback
     * function as an argument. Usage:
     *
     *     BackendMenu::registerCallback(function ($manager) {
     *         $manager->registerMenuItems([...]);
     *     });
     *
     * @param callable $callback A callable function.
     */
    public function registerCallback(callable $callback)
    {
        $this->callbacks[] = $callback;
    }

    /**
     * Registers the back-end menu items.
     * The argument is an array of the main menu items. The array keys represent the
     * menu item codes, specific for the plugin/module. Each element in the
     * array should be an associative array with the following keys:
     * - label - specifies the menu label localization string key, required.
     * - icon - an icon name from the Font Awesome icon collection, required.
     * - url - the back-end relative URL the menu item should point to, required.
     * - permissions - an array of permissions the back-end user should have, optional.
     *   The item will be displayed if the user has any of the specified permissions.
     * - order - a position of the item in the menu, optional.
     * - counter - an optional numeric value to output near the menu icon. The value should be
     *   a number or a callable returning a number.
     * - counterLabel - an optional string value to describe the numeric reference in counter.
     * - sideMenu - an array of side menu items, optional. If provided, the array items
     *   should represent the side menu item code, and each value should be an associative
     *   array with the following keys:
     *      - label - specifies the menu label localization string key, required.
     *      - icon - an icon name from the Font Awesome icon collection, required.
     *      - url - the back-end relative URL the menu item should point to, required.
     *      - attributes - an array of attributes and values to apply to the menu item, optional.
     *      - permissions - an array of permissions the back-end user should have, optional.
     *      - counter - an optional numeric value to output near the menu icon. The value should be
     *        a number or a callable returning a number.
     *      - counterLabel - an optional string value to describe the numeric reference in counter.
     *      - badge - an optional string value to output near the menu icon. The value should be
     *        a string. This value will override the counter if set.
     * @param string $owner Specifies the menu items owner plugin or module in the format Author.Plugin.
     * @param array $definitions An array of the menu item definitions.
     * @throws SystemException
     */
    public function registerMenuItems($owner, array $definitions)
    {
        $validator = Validator::make($definitions, [
            '*.label' => 'required',
            '*.icon' => 'required_without:*.iconSvg',
            '*.url' => 'required',
            '*.sideMenu.*.label' => 'nullable|required',
            '*.sideMenu.*.icon' => 'nullable|required_without:*.sideMenu.*.iconSvg',
            '*.sideMenu.*.url' => 'nullable|required',
        ]);

        if ($validator->fails()) {
            $errorMessage = 'Invalid menu item detected in ' . $owner . '. Contact the plugin author to fix (' . $validator->errors()->first() . ')';
            if (Config::get('app.debug', false)) {
                throw new SystemException($errorMessage);
            }

            Log::error($errorMessage);
        }

        $this->addMainMenuItems($owner, $definitions);
    }

    /**
     * Register an owner alias
     *
     * @param string $owner The owner to register an alias for. Example: Real.Owner
     * @param string $alias The alias to register. Example: Aliased.Owner
     * @return void
     */
    public function registerOwnerAlias(string $owner, string $alias)
    {
        $this->aliases[strtoupper($alias)] = strtoupper($owner);
    }

    /**
     * Dynamically add an array of main menu items
     * @param string $owner
     * @param array  $definitions
     */
    public function addMainMenuItems($owner, array $definitions)
    {
        foreach ($definitions as $code => $definition) {
            $this->addMainMenuItem($owner, $code, $definition);
        }
    }

    /**
     * Dynamically add a single main menu item
     * @param string $owner
     * @param string $code
     * @param array  $definition
     */
    public function addMainMenuItem($owner, $code, array $definition)
    {
        $itemKey = $this->makeItemKey($owner, $code);

        if (isset($this->items[$itemKey])) {
            $definition = array_merge((array) $this->items[$itemKey], $definition);
        }

        $item = array_merge($definition, [
            'code'  => $code,
            'owner' => $owner
        ]);

        $this->items[$itemKey] = MainMenuItem::createFromArray($item);

        if (array_key_exists('sideMenu', $item)) {
            $this->addSideMenuItems($owner, $code, $item['sideMenu']);
        }
    }

    /**
     * @param string $owner
     * @param string $code
     * @return MainMenuItem
     * @throws SystemException
     */
    public function getMainMenuItem(string $owner, string $code)
    {
        $itemKey = $this->makeItemKey($owner, $code);

        if (!array_key_exists($itemKey, $this->items)) {
            throw new SystemException('No main menu item found with key ' . $itemKey);
        }

        return $this->items[$itemKey];
    }

    /**
     * Removes a single main menu item
     * @param $owner
     * @param $code
     */
    public function removeMainMenuItem($owner, $code)
    {
        $itemKey = $this->makeItemKey($owner, $code);
        unset($this->items[$itemKey]);
    }

    /**
     * Dynamically add an array of side menu items
     * @param string $owner
     * @param string $code
     * @param array  $definitions
     */
    public function addSideMenuItems($owner, $code, array $definitions)
    {
        foreach ($definitions as $sideCode => $definition) {
            $this->addSideMenuItem($owner, $code, $sideCode, (array) $definition);
        }
    }

    /**
     * Dynamically add a single side menu item
     * @param string $owner
     * @param string $code
     * @param string $sideCode
     * @param array $definition
     * @return bool
     */
    public function addSideMenuItem($owner, $code, $sideCode, array $definition)
    {
        $itemKey = $this->makeItemKey($owner, $code);

        if (!isset($this->items[$itemKey])) {
            return false;
        }

        $mainItem = $this->items[$itemKey];

        $definition = array_merge($definition, [
            'code'  => $sideCode,
            'owner' => $owner
        ]);

        if (isset($mainItem->sideMenu[$sideCode])) {
            $definition = array_merge((array) $mainItem->sideMenu[$sideCode], $definition);
        }

        $item = SideMenuItem::createFromArray($definition);

        $this->items[$itemKey]->addSideMenuItem($item);
        return true;
    }

    /**
     * Remove multiple side menu items
     *
     * @param string $owner
     * @param string $code
     * @param array  $sideCodes
     * @return void
     */
    public function removeSideMenuItems($owner, $code, $sideCodes)
    {
        foreach ($sideCodes as $sideCode) {
            $this->removeSideMenuItem($owner, $code, $sideCode);
        }
    }

    /**
     * Removes a single main menu item
     * @param string $owner
     * @param string $code
     * @param string $sideCode
     * @return bool
     */
    public function removeSideMenuItem($owner, $code, $sideCode)
    {
        $itemKey = $this->makeItemKey($owner, $code);
        if (!isset($this->items[$itemKey])) {
            return false;
        }

        $mainItem = $this->items[$itemKey];
        $mainItem->removeSideMenuItem($sideCode);
        return true;
    }

    /**
     * Returns a list of the main menu items.
     * @return array
     * @throws SystemException
     */
    public function listMainMenuItems()
    {
        if ($this->items === null && $this->quickActions === null) {
            $this->loadItems();
        }

        if ($this->items === null) {
            return [];
        }

        foreach ($this->items as $item) {
            if ($item->badge) {
                $item->counter = (string) $item->badge;
                continue;
            }
            if ($item->counter === false) {
                continue;
            }

            if ($item->counter !== null && is_callable($item->counter)) {
                $item->counter = call_user_func($item->counter, $item);
            } elseif (!empty((int) $item->counter)) {
                $item->counter = (int) $item->counter;
            } elseif (!empty($sideItems = $this->listSideMenuItems($item->owner, $item->code))) {
                $item->counter = 0;
                foreach ($sideItems as $sideItem) {
                    if ($sideItem->badge) {
                        continue;
                    }
                    $item->counter += $sideItem->counter;
                }
            }

            if (empty($item->counter) || !is_numeric($item->counter)) {
                $item->counter = null;
            }
        }

        return $this->items;
    }

    /**
     * Returns a list of side menu items for the currently active main menu item.
     * The currently active main menu item is set with the setContext methods.
     * @param null $owner
     * @param null $code
     * @return SideMenuItem[]
     * @throws SystemException
     */
    public function listSideMenuItems($owner = null, $code = null)
    {
        $activeItem = null;

        if ($owner !== null && $code !== null) {
            $activeItem = @$this->items[$this->makeItemKey($owner, $code)];
        } else {
            foreach ($this->listMainMenuItems() as $item) {
                if ($this->isMainMenuItemActive($item)) {
                    $activeItem = $item;
                    break;
                }
            }
        }

        if (!$activeItem) {
            return [];
        }

        $items = $activeItem->sideMenu;

        foreach ($items as $item) {
            if ($item->badge) {
                $item->counter = (string) $item->badge;
                continue;
            }
            if ($item->counter !== null && is_callable($item->counter)) {
                $item->counter = call_user_func($item->counter, $item);
                if (empty($item->counter)) {
                    $item->counter = null;
                }
            }
            if (!is_null($item->counter) && !is_numeric($item->counter)) {
                throw new SystemException("The menu item {$activeItem->code}.{$item->code}'s counter property is invalid. Check to make sure it's numeric or callable. Value: " . var_export($item->counter, true));
            }
        }

        return $items;
    }

    /**
     * Registers quick actions in the main navigation.
     *
     * Quick actions are single purpose links displayed to the left of the user menu in the
     * backend main navigation.
     *
     * The argument is an array of the quick action items. The array keys represent the
     * quick action item codes, specific for the plugin/module. Each element in the
     * array should be an associative array with the following keys:
     * - label - specifies the action label localization string key, used as a tooltip, required.
     * - icon - an icon name from the Font Awesome icon collection, required if iconSvg is unspecified.
     * - iconSvg - a custom SVG icon to use for the icon, required if icon is unspecified.
     * - url - the back-end relative URL the quick action item should point to, required.
     * - permissions - an array of permissions the back-end user should have, optional.
     *   The item will be displayed if the user has any of the specified permissions.
     * - order - a position of the item in the menu, optional.
     *
     * @param string $owner Specifies the quick action items owner plugin or module in the format Author.Plugin.
     * @param array $definitions An array of the quick action item definitions.
     * @return void
     * @throws SystemException If the validation of the quick action configuration fails
     */
    public function registerQuickActions($owner, array $definitions)
    {
        $validator = Validator::make($definitions, [
            '*.label' => 'required',
            '*.icon' => 'required_without:*.iconSvg',
            '*.url' => 'required'
        ]);

        if ($validator->fails()) {
            $errorMessage = 'Invalid quick action item detected in ' . $owner . '. Contact the plugin author to fix (' . $validator->errors()->first() . ')';
            if (Config::get('app.debug', false)) {
                throw new SystemException($errorMessage);
            }

            Log::error($errorMessage);
        }

        $this->addQuickActionItems($owner, $definitions);
    }

    /**
     * Dynamically add an array of quick action items
     *
     * @param string $owner
     * @param array  $definitions
     * @return void
     */
    public function addQuickActionItems($owner, array $definitions)
    {
        foreach ($definitions as $code => $definition) {
            $this->addQuickActionItem($owner, $code, $definition);
        }
    }

    /**
     * Dynamically add a single quick action item
     *
     * @param string $owner
     * @param string $code
     * @param array  $definition
     * @return void
     */
    public function addQuickActionItem($owner, $code, array $definition)
    {
        $itemKey = $this->makeItemKey($owner, $code);

        if (isset($this->quickActions[$itemKey])) {
            $definition = array_merge((array) $this->quickActions[$itemKey], $definition);
        }

        $item = array_merge($definition, [
            'code'  => $code,
            'owner' => $owner
        ]);

        $this->quickActions[$itemKey] = QuickActionItem::createFromArray($item);
    }

    /**
     * Gets the instance of a specified quick action item.
     *
     * @param string $owner
     * @param string $code
     * @return QuickActionItem
     * @throws SystemException
     */
    public function getQuickActionItem(string $owner, string $code)
    {
        $itemKey = $this->makeItemKey($owner, $code);

        if (!array_key_exists($itemKey, $this->quickActions)) {
            throw new SystemException('No quick action item found with key ' . $itemKey);
        }

        return $this->quickActions[$itemKey];
    }

    /**
     * Removes a single quick action item
     *
     * @param $owner
     * @param $code
     * @return void
     */
    public function removeQuickActionItem($owner, $code)
    {
        $itemKey = $this->makeItemKey($owner, $code);
        unset($this->quickActions[$itemKey]);
    }

    /**
     * Returns a list of quick action items.
     *
     * @return array
     * @throws SystemException
     */
    public function listQuickActionItems()
    {
        if ($this->items === null && $this->quickActions === null) {
            $this->loadItems();
        }

        if ($this->quickActions === null) {
            return [];
        }

        return $this->quickActions;
    }

    /**
     * Sets the navigation context.
     * The function sets the navigation owner, main menu item code and the side menu item code.
     * @param string $owner Specifies the navigation owner in the format Vendor/Module
     * @param string $mainMenuItemCode Specifies the main menu item code
     * @param string $sideMenuItemCode Specifies the side menu item code
     */
    public function setContext($owner, $mainMenuItemCode, $sideMenuItemCode = null)
    {
        $this->setContextOwner($owner);
        $this->setContextMainMenu($mainMenuItemCode);
        $this->setContextSideMenu($sideMenuItemCode);
    }

    /**
     * Sets the navigation context owner.
     *
     * @param string $owner Specifies the navigation owner in the format Vendor/Module
     */
    public function setContextOwner($owner)
    {
        $this->contextOwner = strtoupper($owner);
    }

    /**
     * Gets the navigation context owner
     */
    public function getContextOwner()
    {
        return $this->aliases[$this->contextOwner] ?? $this->contextOwner;
    }

    /**
     * Specifies a code of the main menu item in the current navigation context.
     * @param string $mainMenuItemCode Specifies the main menu item code
     */
    public function setContextMainMenu($mainMenuItemCode)
    {
        $this->contextMainMenuItemCode = $mainMenuItemCode;
    }

    /**
     * Returns information about the current navigation context.
     * @return mixed Returns an object with the following fields:
     * - mainMenuCode
     * - sideMenuCode
     * - owner
     */
    public function getContext()
    {
        return (object)[
            'mainMenuCode' => $this->contextMainMenuItemCode,
            'sideMenuCode' => $this->contextSideMenuItemCode,
            'owner' => $this->getContextOwner(),
        ];
    }

    /**
     * Specifies a code of the side menu item in the current navigation context.
     * If the code is set to TRUE, the first item will be flagged as active.
     * @param string $sideMenuItemCode Specifies the side menu item code
     */
    public function setContextSideMenu($sideMenuItemCode)
    {
        $this->contextSideMenuItemCode = $sideMenuItemCode;
    }

    /**
     * Determines if a main menu item is active.
     * @param MainMenuItem $item Specifies the item object.
     * @return boolean Returns true if the menu item is active.
     */
    public function isMainMenuItemActive($item)
    {
        return $this->getContextOwner() === strtoupper($item->owner) && $this->contextMainMenuItemCode === $item->code;
    }

    /**
     * Returns the currently active main menu item
     * @return null|MainMenuItem $item Returns the item object or null.
     * @throws SystemException
     */
    public function getActiveMainMenuItem()
    {
        foreach ($this->listMainMenuItems() as $item) {
            if ($this->isMainMenuItemActive($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Determines if a side menu item is active.
     * @param SideMenuItem $item Specifies the item object.
     * @return boolean Returns true if the side item is active.
     */
    public function isSideMenuItemActive($item)
    {
        if ($this->contextSideMenuItemCode === true) {
            $this->contextSideMenuItemCode = null;
            return true;
        }

        return $this->getContextOwner() === strtoupper($item->owner) && $this->contextSideMenuItemCode === $item->code;
    }

    /**
     * Registers a special side navigation partial for a specific main menu.
     * The sidenav partial replaces the standard side navigation.
     * @param string $owner Specifies the navigation owner in the format Vendor/Module.
     * @param string $mainMenuItemCode Specifies the main menu item code.
     * @param string $partial Specifies the partial name.
     */
    public function registerContextSidenavPartial($owner, $mainMenuItemCode, $partial)
    {
        $this->contextSidenavPartials[$owner.$mainMenuItemCode] = $partial;
    }

    /**
     * Returns the side navigation partial for a specific main menu previously registered
     * with the registerContextSidenavPartial() method.
     *
     * @param string $owner Specifies the navigation owner in the format Vendor/Module.
     * @param string $mainMenuItemCode Specifies the main menu item code.
     * @return mixed Returns the partial name or null.
     */
    public function getContextSidenavPartial($owner, $mainMenuItemCode)
    {
        $owner = $this->aliases[strtoupper($owner)] ?? $owner;
        $key = $owner.$mainMenuItemCode;

        return $this->contextSidenavPartials[$key] ?? null;
    }

    /**
     * Removes menu items from an array if the supplied user lacks permission.
     * @param \Backend\Models\User $user A user object
     * @param MainMenuItem[]|SideMenuItem[] $items A collection of menu items
     * @return array The filtered menu items
     */
    protected function filterItemPermissions($user, array $items)
    {
        if (!$user) {
            return $items;
        }

        $items = array_filter($items, static function ($item) use ($user) {
            if (!$item->permissions || !count($item->permissions)) {
                return true;
            }

            return $user->hasAnyAccess($item->permissions);
        });

        return $items;
    }

    /**
     * Internal method to make a unique key for an item.
     * @param string $owner
     * @param string $code
     * @return string
     */
    protected function makeItemKey($owner, $code)
    {
        $owner = strtoupper($owner);
        return ($this->aliases[$owner] ?? $owner) . '.' . strtoupper($code);
    }
}
