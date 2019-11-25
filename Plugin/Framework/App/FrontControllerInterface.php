<?php

/**
 * @category  Crankycyclops
 * @package   Crankycyclops_DiscountCodeUrl
 * @author    James Colannino
 * @copyright Copyright (c) 2019 James Colannino
 * @license   https://www.gnu.org/licenses/gpl-3.0.en.html GPL v3
 */

namespace Crankycyclops\DiscountCodeUrl\Plugin\Framework\App;

class FrontControllerInterface {

	/**
	 * @var \Magento\Framework\App\RequestInterface
	 */
	private $request;

	/**
	 * @var \Crankycyclops\DiscountCodeUrl\Helper\Config
	 */
	private $config;

	/**
	 * @var \Crankycyclops\DiscountCodeUrl\Helper\Cookie
	 */
	private $cookieHelper;

	/**
	 * @var \Magento\SalesRule\Model\Coupon
	 */
	private $couponModel;

	/**
	 * @var \Magento\SalesRule\Model\Rule
	 */
	private $ruleModel;

	/**
	 * @var \Magento\Framework\Message\ManagerInterface
	 */
	private $messageManager;

	/************************************************************************/

	/**
	 * Constructor
	 *
	 * @param \Magento\Framework\App\RequestInterface $request
	 * @param \Magento\Framework\UrlInterface $url
	 * @param \Crankycyclops\DiscountCodeUrl\Helper\Config $config
	 * @param \Crankycyclops\DiscountCodeUrl\Helper\Cookie $cookieHelper
	 * @param \Magento\SalesRule\Model\Coupon $couponModel
	 * @param \Magento\SalesRule\Model\Rule $ruleModel
	 * @param \Magento\Framework\Message\ManagerInterface $messageManager
	 */
	public function __construct(
		\Magento\Framework\App\RequestInterface $request,
		\Crankycyclops\DiscountCodeUrl\Helper\Config $config,
		\Crankycyclops\DiscountCodeUrl\Helper\Cookie $cookieHelper,
		\Magento\SalesRule\Model\Coupon $couponModel,
		\Magento\SalesRule\Model\Rule $ruleModel,
		\Magento\Framework\Message\ManagerInterface $messageManager
	) {
		$this->request = $request;
		$this->config = $config;
		$this->cookieHelper = $cookieHelper;
		$this->couponModel = $couponModel;
		$this->ruleModel = $ruleModel;
		$this->messageManager = $messageManager;
	}

	/************************************************************************/

	/**
	 * If coupon code is provided by the URL, remember it for the duration of
	 * the session.
	 *
	 * @param \Magento\Framework\App\FrontControllerInterface $subject (not used)
	 *
	 * @return void
	 */
	public function beforeDispatch(\Magento\Framework\App\FrontControllerInterface $subject): void {

		if ($this->config->isEnabled()) {

			$queryParameter = $this->config->getUrlParameter();

			// Discount code passed through the URL via query string
			$coupon = $this->request->getParam($queryParameter);

			// If the coupon code didn't come in via a query string, check to
			// see if it was tacked onto the end of the URL. This will only
			// function if your implementation of RequestInterface has
			// implemented the setPathInfo method (in most cases, this
			// should be true, but the interface doesn't require it to be
			// implemented, so better safe than sorry!)
			if (
				!$coupon &&
				$this->config->isUrlPathEnabled() &&
				method_exists($this->request, 'setPathInfo')
			) {

				$requestPath = $this->request->getPathInfo();

				// If a coupon code was included in the URL, fix the URL
				// after extracting it so that we can continue to route normally
				if (preg_match("#/$queryParameter/([^/]+?)/*$#", $requestPath, $matches)) {

					$coupon = $matches[1];
					$realPath = str_replace($matches[0], '', $requestPath);

					if (!$realPath) {
						$realPath = '/';
					}

					$this->request->setPathInfo($realPath);
				}
			}

			if ($coupon) {

				$invalidMessage = "Discount code <strong>$coupon</strong> is invalid";
				$expiredMessage = "Unfortunately, the <strong>$coupon</strong> discount code is expired";
				$consumedMessage = "Unfortunately, the <strong>$coupon</strong> discount code has been fully consumed";

				$this->couponModel->loadByCode($coupon);

				if($this->couponModel->getId()) {

					$this->ruleModel->load($this->couponModel->getRuleId());

					if ($this->ruleModel->getId()) {

						$today = strtotime(date("Y-m-d"));
						$startDay = $this->ruleModel->getFromDate();
						$expirationDay = $this->ruleModel->getToDate();

						$numUses = $this->couponModel->getTimesUsed();
						$maxUses = $this->couponModel->getUsageLimit();

						$usesPerCustomer = $this->couponModel->getUsagePerCustomer();

						// Discount code is expired
						if ($expirationDay && strtotime($expirationDay) < $today) {
							$this->messageManager->addError(__($expiredMessage));
						}

						// Discount hasn't started yet
						else if ($startDay && strtotime($startDay) > $today) {
							$this->messageManager->addError(__($invalidMessage));
						}

						// Coupon has already been fully consumed
						else if ($maxUses && $numUses >= $maxUses) {
							$this->messageManager->addError(__($consumedMessage));
						}

						else {

							$successMessage = "Discount code <strong>$coupon</strong> will be applied to your order during checkout";

							if ($usesPerCustomer && $usesPerCustomer > 0) {

								if ($usesPerCustomer > 1) {
									$successMessage .= " unless you've already fully consumed it (code is only valid for up to $usesPerCustomer orders";
								} else {
									$successMessage .= " unless you've already used it (code is only valid for one order";
								}

								$successMessage .= " per customer)";
							}

							$this->cookieHelper->setCookie($coupon);
							$this->messageManager->addSuccess(__($successMessage));
						}
					}

					else {
						$this->messageManager->addError(__($invalidMessage));
					}
				}

				else {
					$this->messageManager->addError(__($invalidMessage));
				}
			}
		}
	}
}

