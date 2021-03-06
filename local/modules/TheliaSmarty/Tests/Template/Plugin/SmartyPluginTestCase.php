<?php
/*************************************************************************************/
/* This file is part of the Thelia package.                                          */
/*                                                                                   */
/* Copyright (c) OpenStudio                                                          */
/* email : dev@thelia.net                                                            */
/* web : http://www.thelia.net                                                       */
/*                                                                                   */
/* For the full copyright and license information, please view the LICENSE.txt       */
/* file that was distributed with this source code.                                  */
/*************************************************************************************/

namespace TheliaSmarty\Tests\Template\Plugin;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Template\TheliaTemplateHelper;
use Thelia\Tests\ContainerAwareTestCase;
use TheliaSmarty\Template\SmartyParser;

/**
 * Class SmartyPluginTestCase
 * @package TheliaSmarty\Tests\Template\Plugin
 * @author Benjamin Perche <bperche@openstudio.fr>
 */
abstract class SmartyPluginTestCase extends ContainerAwareTestCase
{
    /** @var SmartyParser */
    protected $smarty;

    /**
     * Use this method to build the container with the services that you need.
     */
    protected function buildContainer(ContainerBuilder $container)
    {
        $this->smarty = new SmartyParser(
            $container->get("request"),
            $container->get("event_dispatcher"),
            $parserContext = new ParserContext($container->get("request")),
            $templateHelper = new TheliaTemplateHelper()
        );

        $container->set("thelia.parser", $this->smarty);
        $container->set("thelia.parser.context", $parserContext);

        $this->smarty->addPlugins($this->getPlugin($container));
        $this->smarty->registerPlugins();
    }

    protected function render($template)
    {
        return $this->smarty->fetch(__DIR__.DS."fixtures".DS.$template);
    }

    /**
     * @return \TheliaSmarty\Template\AbstractSmartyPlugin
     */
    abstract protected function getPlugin(ContainerBuilder $container);
}
