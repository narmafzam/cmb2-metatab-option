<?php

namespace MetaTab;

use \Exception;

class MetaTab
{
    protected static $once = FALSE;

    private static $props = array();

    private $defaults = array(
        'key' => 'my_options',
        'regkey' => TRUE,
        'title' => 'My Options',
        'topmenu' => '',
        'postslug' => '',
        'class' => '',
        'menuargs' => array(
            'parent_slug' => '',
            'page_title' => '',
            'menu_title' => '',
            'capability' => 'manage_options',
            'menu_slug' => '',
            'icon_url' => '',
            'position' => NULL,
            'network' => FALSE,
            'view_capability' => '',
        ),
        'jsuri' => '',
        'boxes' => array(),
        'getboxes' => TRUE,
        'plugincss' => TRUE,
        'admincss' => '',
        'tabs' => array(),
        'cols' => 1,
        'resettxt' => 'Reset',
        'savetxt' => 'Save',
        'load' => array(),
    );

    protected $id;

    public function __construct($args)
    {

        // require CMB2
        if (!class_exists('CMB2')) {
            throw new Exception($this . ': CMB2 is required to use this class.');
        }

        // only allow within WP admin area;
        if (!is_admin()) {
            return;
        }

        // set the ID
        $this->id = $this->setID();

        // parse any injected arguments and add to self::$props
        self::$props[$this->getId()] = $this->parseArgsR($args, $this->defaults);

        // validate the properties we were sent
        $this->validateProps();

        // if the menu_slug == parent_slug, set hide to true, prevents duplicate page display
        self::$props[$this->getId()]['hide'] =
            self::$props[$this->getId()]['menuargs']['parent_slug'] == self::$props[$this->getId()]['menuargs']['menu_slug'] &&
            self::$props[$this->getId()]['menuargs']['parent_slug'] != '';

        // add tabs: several actions depend on knowing if tabs are present
        self::$props[$this->getId()]['tabs'] = $this->addTabs();

        // Add actions
        $this->addWpActions();
    }

    public function getId()
    {
        return $this->id;
    }

    private function setID()
    {

        return 'cmo' . rand(1000, 9999);
    }

    public function parseArgsR(&$a, $b)
    {

        $a = (array)$a;
        $b = (array)$b;
        $r = $b;
        foreach ($a as $k => &$v) {
            if (is_array($v) && isset($r[$k])) {
                $r[$k] = $this->parseArgsR($v, $r[$k]);
            } else {
                $r[$k] = $v;
            }
        }

        return $r;
    }

    private function validateProps()
    {

        // if key or title do not exist, throw exception
        if (!self::$props[$this->getId()]['key']) {
            throw new Exception($this . ': Settings key missing.');
        }

        // set JS url
        if (!self::$props[$this->getId()]['jsuri']) {
            self::$props[$this->getId()]['jsuri'] = $this->getPathUrl(__DIR__ . '/../../asset/js/cmb2multiopts.js');
        }

        // set columns to 1 if illegal value sent
        self::$props[$this->getId()]['cols'] = intval(self::$props[$this->getId()]['cols']);
        if (self::$props[$this->getId()]['cols'] > 2 || self::$props[$this->getId()]['cols'] < 1) {
            self::$props[$this->getId()]['cols'] = 1;
        }

        // if menuargs[menu_slug] is set, change the page prop to that
        self::$props[$this->getId()]['page'] = self::$props[$this->getId()]['menuargs']['menu_slug'] ?
            self::$props[$this->getId()]['menuargs']['menu_slug'] : self::$props[$this->getId()]['key'];

        // set page viewing capability; empty string = same as menuargs[capability], false = do not check
        if (!self::$props[$this->getId()]['menuargs']['view_capability']) {
            self::$props[$this->getId()]['menuargs']['view_capability'] =
                self::$props[$this->getId()]['menuargs']['view_capability'] === '' ?
                    self::$props[$this->getId()]['menuargs']['capability'] : FALSE;
        }
    }

    private function addWpActions()
    {

        // Register setting
        if (self::$props[$this->getId()]['regkey']) {
            add_action(
                'admin_init',
                array($this, 'registerSetting')
            );
        }

        // Allow multisite network menu pages
        $net = (is_multisite() && self::$props[$this->getId()]['menuargs']['network'] === TRUE) ? 'network_' : '';

        // Adds page to admin
        add_action(
            $net . 'admin_menu',
            array($this, 'addOptionsPage'),
            12
        );

        // Include CSS for this options page as style tag in head, if tabs are configured
        add_action(
            'admin_head',
            array($this, 'addCss')
        );

        // Adds JS to foot
        add_action(
            'admin_enqueue_scripts',
            array($this, 'addScripts')
        );

        // Adds custom save button field, allowing save button to be added to metaboxes
        add_action(
            'cmb2_render_options_save_button',
            array($this, 'renderOptionsSaveButton'),
            10, 1);
    }

    private function loadActions()
    {

        if (empty(self::$props[$this->getId()]['load'])) {
            return;
        }

        foreach (self::$props[$this->getId()]['load'] as $load) {

            // skip if no action or callback
            if (!isset($load['action']) || !isset($load['callback'])) {
                continue;
            }

            // skip if the [hook] token is not in the [action]
            if (strpos($load['action'], '-[hook]') === FALSE) {
                continue;
            }

            // replace token with page hook
            $load['action'] = str_replace('[hook]', self::$props[$this->getId()]['hook'], $load['action']);

            // make sure if priority is int
            $pri = isset($load['priority']) && intval($load['priority']) > 0 ? intval($load['priority']) : 10;

            // make sure args is int
            $arg = isset($load['args']) && intval($load['args']) > 0 ? intval($load['args']) : 1;

            // add action
            add_action($load['action'], $load['callback'], $pri, $arg);
        }
    }

    public function registerSetting()
    {

        register_setting(self::$props[$this->getId()]['key'], self::$props[$this->getId()]['key']);
    }

    public function addOptionsPage()
    {

        // build arguments
        $args = $this->buildMenuArgs();

        // this is kind of ugly, but so is the WP function!
        self::$props[$this->getId()]['hook'] =
            $args['cb']($args[0], $args[1], $args[2], $args[3], $args[4], $args[5], $args[6]);

        // Include CMB CSS in the head to avoid FOUC, called here as we need the screen ID
        add_action(
            'admin_print_styles-' . self::$props[$this->getId()]['hook'],
            array('CMB2_hookup', 'enqueue_cmb_css')
        );

        // Adds existing metaboxes, see note in function, called here as we need the screen ID
        add_action(
            'add_meta_boxes_' . self::$props[$this->getId()]['hook'],
            array($this, 'addMetaBoxes')
        );

        // On page load, do "metaboxes" actions, called here as we need the screen ID
        add_action(
            'load-' . self::$props[$this->getId()]['hook'],
            array($this, 'doMetaBoxes')
        );

        // Allows pages to call actions
        $this->loadActions();
    }

    private function buildMenuArgs()
    {

        $args = array();

        // set the top menu if either topmenu or the menuargs parent_slug is set
        $parent = self::$props[$this->getId()]['topmenu'] ? self::$props[$this->getId()]['topmenu'] : '';
        $parent = self::$props[$this->getId()]['menuargs']['parent_slug'] ?
            self::$props[$this->getId()]['menuargs']['parent_slug'] : $parent;

        // set the page title; overrides 'title' with menuargs 'page_title' if set
        $pagetitle = self::$props[$this->getId()]['menuargs']['page_title'] ?
            self::$props[$this->getId()]['menuargs']['page_title'] : self::$props[$this->getId()]['title'];

        // sub[0] : parent slug
        if ($parent) {
            // add a post_type get variable, to allow post options pages, if set
            $add = self::$props[$this->getId()]['postslug'] ? '?post_type=' . self::$props[$this->getId()]['postslug'] : '';
            $args[] = $parent . $add;
        }

        // top[0], sub[1] : page title
        $args[] = $pagetitle;

        // top[1], sub[2] : menu title, defaults to page title if not set
        $args[] = self::$props[$this->getId()]['menuargs']['menu_title'] ?
            self::$props[$this->getId()]['menuargs']['menu_title'] : $pagetitle;

        // top[2], sub[3] : capability
        $args[] = self::$props[$this->getId()]['menuargs']['capability'];

        // top[3], sub[4] : menu_slug, defaults to options slug if not set
        $args[] = self::$props[$this->getId()]['menuargs']['menu_slug'] ?
            self::$props[$this->getId()]['menuargs']['menu_slug'] : self::$props[$this->getId()]['key'];

        // top[4], sub[5] : callable function
        $args[] = array($this, 'adminPageDisplay');

        // top menu icon and menu position
        if (!$parent) {

            // top[5] icon url
            $args[] = self::$props[$this->getId()]['menuargs']['icon_url'] ?
                self::$props[$this->getId()]['menuargs']['icon_url'] : '';

            // top[6] menu position
            $args[] = self::$props[$this->getId()]['menuargs']['position'] === NULL ?
                NULL : intval(self::$props[$this->getId()]['menuargs']['position']);
        } // sub[6] : unused, but returns consistent array
        else {
            $args[] = NULL;
        }

        // set which WP function will be called based on $parent
        $args['cb'] = $parent ? 'add_submenu_page' : 'add_menu_page';

        return $args;
    }

    public function addScripts()
    {

        global $hook_suffix;

        // do not run if not a CMO page
        if ($hook_suffix !== self::$props[$this->getId()]['hook']) {
            return;
        }

        // 'postboxes' needed for metaboxes to work properly
        wp_enqueue_script('postbox');

        // toggle the postboxes
        add_action(
            'admin_print_footer_scripts',
            array($this, 'togglePostboxes')
        );

        // only add the main script to the options page if there are tabs present
        if (empty(self::$props[$this->getId()]['tabs'])) {
            return;
        }

        // if self::$props['jsuri'] is empty, throw exception
        if (!self::$props[$this->getId()]['jsuri']) {
            throw new Exception($this . ': Tabs included but JS file not specified.');
        }

        // check to see if file exists, throws exception if it does not
        $headers = @get_headers(self::$props[$this->getId()]['jsuri']);
        if ($headers[0] == 'HTTP/1.1 404 Not Found') {
            throw new Exception($this . ': Passed Javascript file missing.');
        }

        // enqueue the script
        wp_enqueue_script(
            self::$props[$this->getId()]['page'] . '-admin',
            self::$props[$this->getId()]['jsuri'],
            array('postbox'),
            FALSE,
            TRUE
        );

        // localize script to give access to this page's slug
        wp_localize_script(self::$props[$this->getId()]['page'] . '-admin', 'cmb2OptTabs', array(
            'key' => self::$props[$this->getId()]['page'],
            'posttype' => self::$props[$this->getId()]['postslug'],
            'defaulttab' => self::$props[$this->getId()]['tabs'][0]['id'],
        ));
    }

    public function renderOptionsSaveButton()
    {
        global $hook_suffix;

        if ($hook_suffix !== self::$props[$this->getId()]['hook'] || self::$props[$this->getId()]['hide']) {
            return;
        }

        $args = func_get_args();
        echo $this->renderSaveButton($args[0]->args['desc']);
    }

    public function togglePostboxes()
    {

        echo '<script>jQuery(document).ready(function(){postboxes.add_postbox_toggles("postbox-container");});</script>';
    }

    public function addCss()
    {

        // if tabs are not being used, return
        if (empty(self::$props[$this->getId()]['tabs']) || self::$props[$this->getId()]['admincss'] === FALSE) {
            return;
        }

        $css = '';

        // add css to clean up tab styles in admin when used in a postbox
        if (self::$props[$this->getId()]['plugincss'] === TRUE) {
            $css .= '<style type="text/css" id="CMO-cleanup-css">';
            $css .= '.' . self::$props[$this->getId()]['page'] . '.cmb2-options-page #poststuff h2.nav-tab-wrapper{padding-bottom:0;margin-bottom: 20px;}';
            $css .= '.' . self::$props[$this->getId()]['page'] . '.cmb2-options-page .opt-hidden{display:none;}';
            $css .= '.' . self::$props[$this->getId()]['page'] . '.cmb2-options-page #side-sortables{padding-top:22px;}';
            $css .= '</style>';
        }

        // add user-injected CSS; added as separate style tag in case it is malformed
        if (!empty(self::$props[$this->getId()]['admincss'])) {
            $css = '<style type="text/css" id="CMO-exta-css">';
            $css .= self::$props[$this->getId()]['admincss'];
            $css .= '</style>';
        }

        echo $css;
    }

    public function addMetaBoxes()
    {

        // get the metaboxes
        self::$props[$this->getId()]['boxes'] = $this->cmb2MetaBoxes();

        // exit this method if no metaboxes are present
        if (empty(self::$props[$this->getId()]['boxes'])) {
            return;
        }

        foreach (self::$props[$this->getId()]['boxes'] as $box) {

            // skip if this should not be shown
            if (!$this->shouldShow($box)) {
                continue;
            }

            $mid = $box->meta_box['id'];

            // add notice if settings are saved
            add_action('cmb2_save_options-page_fields_' . $mid,
                array($this, 'settingsNotices'),
                10, 2);

            // add callback if tabs are configured which hides metaboxes until moved into proper tabs if not in sidebar
            if (!empty(self::$props[$this->getId()]['tabs']) && $box->meta_box['context'] !== 'side') {
                add_filter('postbox_classes_' . self::$props[$this->getId()]['hook'] . '_' . $mid,
                    array($this, 'hideMetaBoxClass'));
            }

            // if boxes are closed by default...
            if ($box->meta_box['closed']) {
                add_filter('postbox_classes_' . self::$props[$this->getId()]['hook'] . '_' . $mid,
                    array($this, 'closeMetaBoxClass'));
            }

            // add meta box
            add_meta_box(
                $box->meta_box['id'],
                $box->meta_box['title'],
                array($this, 'metaBoxCallback'),
                self::$props[$this->getId()]['hook'],
                $box->meta_box['context'],
                $box->meta_box['priority']
            );
        }
    }

    private function shouldShow($box)
    {

        // if the show_on key is not set, don't show
        if (!isset($box->meta_box['show_on']['key'])) {
            return FALSE;
        }

        // if the key is set but is not set to options-page, don't show
        if ($box->meta_box['show_on']['key'] != 'options-page') {
            return FALSE;
        }

        // if this options key is not in the show_on value, don't show
        if (!in_array(self::$props[$this->getId()]['page'], $box->meta_box['show_on']['value'])) {
            return FALSE;
        }

        return TRUE;
    }

    public function hideMetaBoxClass($classes)
    {

        $classes[] = 'opt-hidden';

        return $classes;
    }

    public function closeMetaBoxClass($classes)
    {

        $classes[] = 'closed';

        return $classes;
    }

    public function doMetaBoxes()
    {

        do_action('add_meta_boxes_' . self::$props[$this->getId()]['hook'], NULL);
        do_action('add_meta_boxes', self::$props[$this->getId()]['hook'], NULL);
    }

    public function metaBoxCallback($post, $metabox)
    {

        // get the metabox, fishing the ID out of the arguments array
        $cmb = cmb2_get_metabox($metabox['id'], self::$props[$this->getId()]['key']);

        if ($this->shouldSave($cmb)) {
            // save fields
            $cmb->save_fields(self::$props[$this->getId()]['key'], $cmb->mb_object_type(), $_POST);
        } else if ($this->shouldReset($cmb)) {
            // Reset fields
            delete_option(self::$props[$this->getId()]['key']);
        }

        // show the fields
        $cmb->show_form();
    }

    private function shouldReset($cmb)
    {

        // are these values set?
        if (!isset($_POST['reset-cmb'], $_POST['object_id'], $_POST[$cmb->nonce()])) {
            return FALSE;
        }

        // does the nonce match?
        if (!wp_verify_nonce($_POST[$cmb->nonce()], $cmb->nonce())) {
            return FALSE;
        }

        // does the object_id equal the settings key?
        if (!$_POST['object_id'] == self::$props[$this->getId()]['key']) {
            return FALSE;
        }

        return TRUE;
    }

    private function shouldSave($cmb)
    {

        // was this flagged to save fields?
        if (!$cmb->prop('save_fields')) {
            return FALSE;
        }

        // are these values set?
        if (!isset($_POST['submit-cmb'], $_POST['object_id'], $_POST[$cmb->nonce()])) {
            return FALSE;
        }

        // does the nonce match?
        if (!wp_verify_nonce($_POST[$cmb->nonce()], $cmb->nonce())) {
            return FALSE;
        }

        // does the object_id equal the settings key?
        if (!$_POST['object_id'] == self::$props[$this->getId()]['key']) {
            return FALSE;
        }

        return TRUE;
    }

    public function adminPageDisplay()
    {

        // this is only set to true if a menu sub-item has the same slug as the parent
        if (self::$props[$this->getId()]['hide']) {
            return;
        }

        // check page viewing capability
        if (self::$props[$this->getId()]['menuargs']['view_capability'] !== FALSE) {
            if (!current_user_can(self::$props[$this->getId()]['menuargs']['view_capability'])) {
                return;
            }
        }

        // get top of page
        $page = $this->adminPageTop();

        // if there are metaboxes to display, add form and boxes
        if (!empty(self::$props[$this->getId()]['boxes'])) {
            $page .= $this->adminPageForm();
        }

        // get bottom of page
        $page .= $this->adminPageBottom();

        echo $page;

        // reset the notices flag
        self::$once = FALSE;
    }

    private function adminPageTop()
    {

        // standard classes, includes page id
        $classes = 'wrap cmb2-options-page cmo-options-page ' . self::$props[$this->getId()]['page'];
        $filterable = '';

        // add any extra configured classes
        if (!empty(self::$props[$this->getId()]['class'])) {
            $classes .= ' ' . self::$props[$this->getId()]['class'];
        }

        $ret = '<div class="' . $classes . '">';
        $ret .= '<h2>' . esc_html(get_admin_page_title()) . '</h2>';
        $ret .= '<div class="cmo-before-form">';

        // note this now passes the page slug as a second argument
        $ret .= apply_filters('cmb2metatabs_before_form', $filterable, self::$props[$this->getId()]['page']);

        $ret .= '</div>';

        return $ret;
    }

    private function adminPageBottom()
    {

        $filterable = '';

        $ret = '<div class="cmo-after-form">';

        // note this now passes the page slug as a second argument
        $ret .= apply_filters('cmb2metatabs_after_form', $filterable, self::$props[$this->getId()]['page']);

        $ret .= '</div>';
        $ret .= '</div>';

        return $ret;
    }

    private function adminPageForm()
    {

        // form wraps all tabs
        $ret = '<form class="cmb-form" method="post" id="cmo-options-form" '
            . 'enctype="multipart/form-data" encoding="multipart/form-data">';

        // hidden object_id field
        $ret .= '<input type="hidden" name="object_id" value="' . self::$props[$this->getId()]['key'] . '">';

        // wp nonce fields
        $ret .= wp_nonce_field('meta-box-order', 'meta-box-order-nonce', FALSE, FALSE);
        $ret .= wp_nonce_field('closedpostboxes', 'closedpostboxesnonce', FALSE, FALSE);

        // add postbox, which allows use of metaboxes
        $ret .= '<div id="poststuff">';

        // main column
        $ret .= '<div id="post-body" class="metabox-holder columns-' . self::$props[$this->getId()]['cols'] . '">';

        // if two columns are called for
        if (self::$props[$this->getId()]['cols'] == 2) {

            // add markup for sidebar
            $ret .= '<div id="postbox-container-1" class="postbox-container">';
            $ret .= '<div id="side-sortables" class="meta-box-sortables ui-sortable">';

            ob_start();

            // add sidebar metaboxes
            do_meta_boxes(self::$props[$this->getId()]['hook'], 'side', NULL);

            $ret .= ob_get_clean();

            $ret .= '</div></div>';  // close sidebar
        }

        // open postbox container
        $ret .= '<div id="postbox-container-';
        $ret .= self::$props[$this->getId()]['cols'] == 2 ? '2' : '1';
        $ret .= '" class="postbox-container">';

        // add tabs; the sortables container is within each tab
        $ret .= $this->renderTabs();

        ob_start();

        // place normal boxes, note that 'normal' and 'advanced' are rendered together when using tabs
        do_meta_boxes(self::$props[$this->getId()]['hook'], 'normal', NULL);
        do_meta_boxes(self::$props[$this->getId()]['hook'], 'advanced', NULL);

        $ret .= ob_get_clean();

        $ret .= '</div></div></div>';

        // add submit button if resettxt or savetxt was included
        if (self::$props[$this->getId()]['resettxt'] || self::$props[$this->getId()]['savetxt']) {
            $ret .= '<div style="clear:both;">';
            $ret .= $this->renderResetButton(self::$props[$this->getId()]['resettxt']);
            $ret .= $this->renderSaveButton(self::$props[$this->getId()]['savetxt']);
            $ret .= '</div>';
        }

        $ret .= '</form>';

        return $ret;
    }

    public function renderResetButton($text = '')
    {

        return $text ? '<input type="submit" name="reset-cmb" value="' . $text . '" class="button">' : '';
    }

    public function renderSaveButton($text = '')
    {

        return $text ? '<input type="submit" name="submit-cmb" value="' . $text . '" class="button-primary">' : '';
    }

    public function settingsNotices($object_id, $cmb_id)
    {

        // bail if this isn't a notice for this page or we've already added a notice
        if ($object_id !== self::$props[$this->getId()]['key'] || empty($cmb_id) || self::$once) {
            return;
        }

        // add notifications
        add_settings_error(self::$props[$this->getId()]['key'] . '-notices', '', __('Settings updated.', 'cmb2'), 'updated');
        settings_errors(self::$props[$this->getId()]['key'] . '-notices');

        // set the flag so we don't pile up notices
        self::$once = TRUE;
    }

    private function renderTabs()
    {

        if (empty(self::$props[$this->getId()]['tabs'])) {
            return '';
        }

        $containers = '';
        $tabs = '';

        foreach (self::$props[$this->getId()]['tabs'] as $tab) {

            // add tabs navigation
            $tabs .= '<a href="#" id="opt-tab-' . $tab['id'] . '" class="nav-tab opt-tab" ';
            $tabs .= 'data-optcontent="#opt-content-' . $tab['id'] . '">';
            $tabs .= $tab['title'];
            $tabs .= '</a>';

            // add tabs containers, javascript will use the data attribute to move metaboxes to within proper tab
            $contents = implode(',', $tab['boxes']);

            // tab container markup
            $containers .= '<div class="opt-content" id="opt-content-' . $tab['id'] . '" ';
            $containers .= ' data-boxes="' . $contents . '">';
            $containers .= $tab['desc'];
            $containers .= '<div class="meta-box-sortables ui-sortable">';
            $containers .= '</div>';
            $containers .= '</div>';
        }

        // add the tab structure to the page
        $return = '<h2 class="nav-tab-wrapper">';
        $return .= $tabs;
        $return .= '</h2>';
        $return .= $containers;

        return $return;
    }

    private function cmb2MetaBoxes()
    {

        // add any injected metaboxes
        $boxes = self::$props[$this->getId()]['boxes'];

        // if boxes is not empty, check to see if they're CMB2 objects, or strings
        if (!empty($boxes)) {
            foreach ($boxes as $key => $box) {
                if (!is_object($box)) {
                    $boxes[$key] = CMB2_Boxes::get($box);
                }
            }
        }

        // if $boxes is still empty and getboxes is true, try grabbing boxes from CMB2
        $boxes = (empty($boxes) && self::$props[$this->getId()]['getboxes'] === TRUE) ? CMB2_Boxes::get_all() : $boxes;

        return $boxes;
    }

    private function addTabs()
    {

        $tabs = self::$props[$this->getId()]['tabs'];

        return $tabs;
    }

    public function __toString()
    {
        return get_class($this);
    }

    public function getPathUrl($path, $protocol = 'http://')
    {
        if (defined('WP_SITEURL')) {
            return WP_SITEURL . str_replace($_SERVER['DOCUMENT_ROOT'], '', realpath($path));
        } else {
            return $protocol . $_SERVER['HTTP_HOST'] . str_replace($_SERVER['DOCUMENT_ROOT'], '', realpath($path));
        }
    }
}