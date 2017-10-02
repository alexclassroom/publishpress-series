<?php
namespace OrganizeSeries\domain\services;

use Closure;
use DomainException;
use InvalidArgumentException;
use OrganizeSeries\application\Root;

/**
 * AssetRegistry
 * This class is where all assets for OrganizeSeries are registered.
 *
 * @package OrganizeSeries\domain\services
 * @author  Darren Ethier
 * @since   1.0.0
 */
class AssetRegistry
{
    private $js_data = array();
    private $i18n = array();
    private $on_demand_script_callbacks = array();


    /**
     * This keeps track of all scripts with registered data.  It is used to prevent duplicate data objects setup in the
     * page source.
     * @var array
     */
    private $script_handles_with_data = array();


    public function __construct()
    {
        $this->initializeJsData();
        $this->initializeI18n();
        add_action('wp_enqueue_scripts', array($this, 'scripts'), 1);
        add_action('admin_enqueue_scripts', array($this, 'scripts'), 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueueData'), 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueueData'), 2);
        add_action('wp_print_footer_scripts', array($this, 'enqueueData'), 1);
        add_action('admin_print_footer_scripts', array($this, 'enqueueData'), 1);
    }


    private function initializeJsData()
    {
        $this->js_data = array(
            'url' => site_url(),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'debug' => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG
        );
    }


    private function initializeI18n()
    {
        $this->i18n = array();
    }


    /**
     * Callback for the WP script actions.
     * Used to register globally accessible core scripts.
     * Also used to add the eejs.data object to the source for any js having eejs-core as a dependency.
     *
     * @throws DomainException
     */
    public function scripts()
    {
        $this->registerGlobalScripts();
        $this->registerOnDemandScripts();
    }


    /**
     * This is where all global script registration happens.  These are scripts that are exposed globally for
     * dependencies elsewhere.
     * @throws DomainException
     */
    private function registerGlobalScripts()
    {
        wp_register_script(
            'osjs',
            Root::coreMeta()->assetsUrl() . 'dist/osjs.dist.js',
            array(),
            Root::coreMeta()->getVersion(),
            true
        );
    }


    /**
     * This loops through all registered callbacks on the $on_demand_script_callbacks property and invokes the callbacks.
     */
    private function registerOnDemandScripts()
    {
        foreach ($this->on_demand_script_callbacks as $callback) {
            $callback();
        }
    }


    /**
     * Client code can use this to register a callback that will be fired on any *enqueue_scripts hook that
     * fired on the request.  Typically this is used to run something like `wp_register_script` etc.
     *
     * @param Closure $callback
     */
    public function registerOnDemandCallback(Closure $callback){
        $this->on_demand_script_callbacks[] = $callback;
    }



    /**
     * Call back for the script print in frontend and backend.
     * Used to call wp_localize_scripts so that data can be added throughout the runtime until this later hook point.
     *
     * @since 4.9.31.rc.015
     */
    public function enqueueData()
    {
        $this->removeAlreadyRegisteredDataForScriptHandles();
        wp_localize_script('osjs', '_osConfig', $this->js_data);
        wp_localize_script('osjs', '_osi18n', $this->i18n);
        $this->addRegisteredScriptHandlesWithData('osjs');
    }



    /**
     * Used to add data to _osConfig data object.
     * Note:  Overriding existing data is not allowed.
     * Data will be accessible as a javascript object when you list `osjs` as a dependency for your javascript.
     * If the data you add is something like this:
     *  $this->addData( 'my_plugin_data', array( 'foo' => 'gar' ) );
     * It will be exposed in the page source as:
     *  _osConfig.my_plugin_data.foo = gar
     *
     * @param string       $key   Key used to access your data
     * @param string|array $value Value to attach to key
     * @throws InvalidArgumentException
     */
    public function addData($key, $value)
    {
        if ($this->verifyDataNotExisting($key)) {
            $this->js_data[$key] = $value;
        }
    }


    /**
     * Used to add register a translation string on the i18n property.
     * Note, if the provided identifier already exists when this is called, then the given translation_string will
     * override what was already registered earlier in the request.
     *
     * @param string $identifier Will be used to identify the string on the exposed _osi18n js object
     *                           (_osi18n.{identifier})
     * @param string $translation_string  Should be the localized string.
     */
    public function addI18n($identifier, $translation_string)
    {
        $this->i18n[$identifier] = $translation_string;
    }


    /**
     * Similar to addI18n except this simply merges the incoming array with the i18n property.
     *
     * @param array $translations
     */
    public function pushI18n(array $translations)
    {
        $this->i18n = array_merge($this->i18n, $translations);
    }



    /**
     * Similar to addData except this allows for users to push values to an existing key where the values on key are
     * elements in an array.
     * When you use this method, the value you include will be appended to the end of an array on $key.
     * So if the $key was 'test' and you added a value of 'my_data' then it would be represented in the javascript
     * object like this, _osConfig.test = [ my_data,
     * ]
     * If there has already been a scalar value attached to the data object given key, then
     * this will throw an exception.
     *
     * @param string       $key   Key to attach data to.
     * @param string|array $value Value being registered.
     * @throws InvalidArgumentException
     */
    public function pushData($key, $value)
    {
        if (isset($this->js_data[$key])
            && ! is_array($this->js_data[$key])
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    __(
                        'The value for %1$s is already set and it is not an array. The %2$s method can only be used to push values to this data element when it is an array.',
                        'organize-series'
                    ),
                    $key,
                    __METHOD__
                )
            );
        }
        $this->js_data[$key][] = $value;
    }



    /**
     * Retrieve registered data.
     *
     * @param string $key Name of key to attach data to.
     * @return mixed                If there is no for the given key, then false is returned.
     */
    public function getData($key)
    {
        return isset($this->js_data[$key])
            ? $this->js_data[$key]
            : false;
    }



    /**
     * Verifies whether the given data exists already on the js_data array property.
     * Overriding data is not allowed.
     *
     * @param string $key Index for data.
     * @return bool        If valid then return true.
     * @throws InvalidArgumentException if data already exists.
     */
    protected function verifyDataNotExisting($key)
    {
        if (isset($this->js_data[$key])) {
            if (is_array($this->js_data[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        __(
                            'The value for %1$s already exists in the %2$s property.
                            Overrides are not allowed. Since the value of this data is an array, you may want to use the
                            %3$s method to push your value to the array.',
                            'organize-series'
                        ),
                        $key,
                        __CLASS__ . '::js_data',
                        __CLASS__ . '::pushData()'
                    )
                );
            }
            throw new InvalidArgumentException(
                sprintf(
                    __(
                        'The value for %1$s already exists in the %2$s property. Overrides are not
                        allowed.  Consider attaching your value to a different key',
                        'organize-series'
                    ),
                    $key,
                    __CLASS__ . '::js_data'
                )
            );
        }
        return true;
    }



    /**
     * This is used to set registered script handles that have data.
     * @param string $script_handle
     */
    private function addRegisteredScriptHandlesWithData($script_handle)
    {
        $this->script_handles_with_data[$script_handle] = $script_handle;
    }


    /**
     * Checks WP_Scripts for all of each script handle registered internally as having data and unsets from the
     * Dependency stored in WP_Scripts if its set.
     */
    private function removeAlreadyRegisteredDataForScriptHandles()
    {
        if (empty($this->script_handles_with_data)) {
            return;
        }
        foreach ($this->script_handles_with_data as $script_handle) {
            $this->removeAlreadyRegisteredDataForScriptHandle($script_handle);
        }
    }


    /**
     * Removes any data dependency registered in WP_Scripts if its set.
     * @param string $script_handle
     */
    private function removeAlreadyRegisteredDataForScriptHandle($script_handle)
    {
        if (isset($this->script_handles_with_data[$script_handle])) {
            global $wp_scripts;
            if ($wp_scripts->get_data($script_handle, 'data')) {
                unset(
                    $wp_scripts->registered[$script_handle]->extra['data'],
                    $this->script_handles_with_data[$script_handle]
                );
            }
        }
    }
}
