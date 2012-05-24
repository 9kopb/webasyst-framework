<?php 

class siteSettingsAction extends waViewAction
{   
    
    public function execute()
    {   
        $routes = wa()->getRouting()->getRoutes(siteHelper::getDomain());
        $apps = wa()->getApps();
               
        foreach ($routes as $route_id => &$route) {
            if (isset($route['app']) && isset($apps[$route['app']])) {
                $route['app'] = $apps[$route['app']];
            } elseif (!isset($route['redirect'])) {
                unset($routes[$route_id]);
            }
        }
        
        $temp = array();
        foreach ($apps as $app_id => $app) {
            if (isset($app['frontend'])) {
                $temp[$app_id] = array(
                    'id' => $app_id,
                    'icon' => $app['icon'],
                    'name' => $app['name']
                );
            } 
        }
        $this->view->assign('apps', $temp);
        $this->view->assign('routes', $routes);
        $this->view->assign('domain_id', siteHelper::getDomainId());
        $this->view->assign('domain', siteHelper::getDomain());
        $this->view->assign('domains', siteHelper::getDomains(true));        
        $domain = siteHelper::getDomain();
        $domain_config_path = $this->getConfig()->getConfigPath('domains/'.$domain.'.php');
        if (file_exists($domain_config_path)) {
            $domain_config = include($domain_config_path);
        } else {
            $domain_config = array();
        }
        $u = parse_url('http://'.$domain);
        $path = isset($u['path']) ? $u['path'] : '';        
        if (!isset($domain_config['apps']) || !$domain_config['apps']) {
            $this->view->assign('domain_apps_type', 0);
            $domain_config['apps'] = wa()->getFrontendApps($domain, isset($domain_config) && isset($domain_config['name']) ? 
                $domain_config['name'] : null);
        } else {
            $this->view->assign('domain_apps_type', 1);            
        }
        $this->view->assign('domain_apps', $domain_config['apps']);
        $this->getStaticFiles($domain);
        $this->view->assign('url', $this->getDomainUrl($domain));
        $this->view->assign('title', siteHelper::getDomain('title'));
    }
    
    
    protected function getDomainUrl($domain)
    {
        $u1 = rtrim(wa()->getRootUrl(false, false), '/');
        $u2 = rtrim(wa()->getRootUrl(false, true), '/');
        $domain_parts = parse_url('http://'.$domain);
        $u = isset($domain_parts['path']) ? $domain_parts['path'] : '';
        if ($u1 != $u2 && substr($u, 0, strlen($u1)) == $u1) {
             $u = $u2.substr($u, strlen($u1));
        }         
        return $domain_parts['host'].$u;
    }
    
    protected function getRouteUrl($path, $route) 
    {
        $url = $route['url'];
        $url = preg_replace('/\[([i|s]?:[a-z_]+)\]/ui', '', $url);
        $url = preg_replace('!(/{2,}|/\*)$!i', '/', $url);
        $url = str_replace('*', '', $url);
        return $path.'/'.$url;
    }
        
    /**
     * Prepare favicon and robots.txt
     * 
     * @param string $domain
     */
    protected function getStaticFiles($domain)
    {
        $path = wa()->getDataPath(null, true).'/data/'.$domain.'/favicon.ico';
        if (file_exists($path)) {
            $favicon = wa()->getDataUrl('data/'.$domain.'/favicon.ico', true);
        } else {
            $favicon = 'http://'.$domain.'/favicon.ico';
        }
        $path = wa()->getDataPath(null, true).'/data/'.$domain.'/robots.txt';
        if (file_exists($path)) {
            $robots = file_get_contents($path);
        } else {
            $robots = '';
        }
        $this->view->assign('robots', $robots);
        $this->view->assign('favicon', $favicon);
        if (strpos($domain, '/') !== false) {
            $this->view->assign('favicon_message', sprintf(_w('Favicon image you upload here will not take effect for you website %s because your website is set for a subfolder on a domain. Favicon uploaded using the form above will be set only for websites set from the domain root folder.'), $domain));
            $this->view->assign('robots_message', sprintf(_w('Rules you set above for robots.txt will not take effect for you website %s because your website is set for a subfolder on a domain. Rules for robots.txt from the form above will be effective only for websites set to the domain root folder.'), $domain));    
        } else {
            $root_path = $this->getConfig()->getRootPath();
            if (file_exists($root_path.'/favicon.ico')) {
                $this->view->assign('favicon_message', _w('File favicon.ico exists in the Webasyst framework installation folder. The favicon you upload here will be overridden by the icon uploaded as file unless you delete this file.'));                
            }
            if (file_exists($root_path.'/robots.txt')) {
                $this->view->assign('robots_message', _w('File robots.txt exists in the Webasyst framework installation folder. Rules for robots.txt you specify above will not take effect unless you delete this file.'));
            }
        }
    }
}