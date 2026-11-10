<?php
declare(strict_types=1);

namespace Backstage\Bridge\Api\Data;

/**
 * Política de notificació explícita: cap correu s'envia per defecte.
 *
 * @api
 */
interface NotificationPolicyInterface
{
    public const MODE_SEND = 'send';
    public const MODE_SUPPRESS = 'suppress';

    /**
     * @return string
     */
    public function getMode();

    /**
     * @param string $mode
     * @return $this
     */
    public function setMode($mode);

    /**
     * @return string|null
     */
    public function getTemplate();

    /**
     * @param string|null $template
     * @return $this
     */
    public function setTemplate($template);

    /**
     * @return string|null
     */
    public function getComment();

    /**
     * @param string|null $comment
     * @return $this
     */
    public function setComment($comment);
}
