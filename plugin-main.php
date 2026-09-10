<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class AgentApiConfig extends PluginConfig
{
    function getOptions()
    {
        return ['database_timezone' => new TimezoneField([
            'label' => 'Database timestamp timezone', 'default' => 'UTC',
            'hint' => 'Timezone used by the existing ticket timestamps; this does not change the database timezone.'
        ])];
    }
}

class AgentApiPlugin extends Plugin
{
    var $config_class = 'AgentApiConfig';

    function bootstrap()
    {
        $timezone = $this->getConfig()->get('database_timezone', 'UTC');
        Signal::connect('api', function ($dispatcher) use ($timezone) {
            require_once __DIR__ . '/api.php';
            AgentApiController::$timezone = $timezone;
            $dispatcher->append(url('^/agent/v1/', patterns('AgentApiController',
                url_get('^identity$', 'identity'),
                url_get('^tickets$', 'tickets'),
                url_get('^statuses$', 'statuses'),
                url_get('^tickets/(?P<id>\d+)$', 'ticket'),
                url_post('^tickets/(?P<id>\d+)/claim$', 'claim'),
                url_post('^tickets/(?P<id>\d+)/assignee$', 'assignee'),
                url_post('^tickets/(?P<id>\d+)/reply$', 'reply')
            )));
        });
    }
}
