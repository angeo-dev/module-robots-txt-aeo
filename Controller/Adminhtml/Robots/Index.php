<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Controller\Adminhtml\Robots;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Angeo_RobotsTxtAeo::dashboard';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('Angeo — Robots.txt AEO'));
        return $resultPage;
    }
}
