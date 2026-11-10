<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Data;

use Backstage\Bridge\Api\Data\NotificationPolicyInterface;
use Magento\Framework\DataObject;

class NotificationPolicy extends DataObject implements NotificationPolicyInterface
{
    public function getMode()
    {
        return (string)$this->getData('mode');
    }

    public function setMode($mode)
    {
        return $this->setData('mode', $mode);
    }

    public function getTemplate()
    {
        $value = $this->getData('template');
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setTemplate($template)
    {
        return $this->setData('template', $template);
    }

    public function getComment()
    {
        $value = $this->getData('comment');
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setComment($comment)
    {
        return $this->setData('comment', $comment);
    }
}
