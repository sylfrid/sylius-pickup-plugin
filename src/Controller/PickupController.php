<?php
/**
 * @author    Matthieu Vion
 * @copyright 2018 Magentix
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://github.com/magentix/pickup-plugin
 */
declare(strict_types=1);

namespace Magentix\SyliusPickupPlugin\Controller;

use Magentix\SyliusPickupPlugin\Shipping\Calculator\CalculatorInterface as PickupCalculatorInterface;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;

final class PickupController extends AbstractController
{

    /**
     * @var ServiceRegistryInterface $calculatorRegistry
     */
    private $calculatorRegistry;

    /**
     * @var RepositoryInterface
     */
    private $countryRepository;

    /**
     * @param ServiceRegistryInterface $calculatorRegistry
     * @param RepositoryInterface $countryRepository
     */
    public function __construct(
        ServiceRegistryInterface $calculatorRegistry,
        RepositoryInterface $countryRepository
    )
    {
        $this->calculatorRegistry = $calculatorRegistry;
        $this->countryRepository = $countryRepository;
    }

    /**
     * Display Pickup List
     *
     * @param Request $request
     * @param string|null $method
     * @return Response
     */
    public function listAction(Request $request, ?string $method): Response
    {
        $calculator = $this->getCalculator($method);

        $params = $request->request->all();

        $pickupTemplate = $this->getDefaultTemplate();
        $pickupCurrentId = null;
        $pickupList = [];
        $currentAddress = null;

        /** @var PickupCalculatorInterface $calculator */
        if ($calculator instanceof PickupCalculatorInterface) {
            if (!empty($calculator->getPickupTemplate())) {
                $pickupTemplate = $calculator->getPickupTemplate();
            }

            $cart = $this->getCurrentCart();
            if (null !== $cart->getId()) {
                $cart = $this->getOrderRepository()->findCartForSummary($cart->getId());
                $address = $cart->getShippingAddress();

                $shipment = $cart->getShipments()->current();
                $pickupCurrentId = $shipment->getPickupId();

                foreach ($params as $field => $value) {
                    $setter = 'set' . preg_replace('/_/', '', ucwords($field, '_'));
                    if (method_exists($address, $setter)) {
                        $address->$setter($value);
                    }
                }
                $currentAddress = $address;

                $pickupList = $calculator->getPickupList($address, $cart, $this->getMethod($method));
            }
        }

        $pickup = [
            'pickup' => [
                'current_id' => $pickupCurrentId,
                'list' => $pickupList,
            ],
            'address' => $currentAddress,
            'countries' => $this->getAvailableCountries(),
            'index' => $request->get('index', 0),
            'code' => $method,
        ];

        return $this->render($pickupTemplate, ['method' => $pickup]);
    }

    /**
     * Retrieve Shipping Method Calculator
     *
     * @param string|null $shippingMethod
     * @return CalculatorInterface|null
     */
    protected function getCalculator(?string $shippingMethod): ?CalculatorInterface
    {
        $method = $this->getMethod($shippingMethod);

        if ($method === null) {
            return null;
        }

        /** @var CalculatorInterface $calculator */
        $calculator = $this->calculatorRegistry->get($method->getCalculator());

        return $calculator;
    }

    /**
     * Retrieve Shipping Method
     *
     * @param string|null $shippingMethod
     * @return ShippingMethod|null
     */
    protected function getMethod(?string $shippingMethod): ?ShippingMethod
    {
        /** @var ShippingMethod|null $method */
        $method = $this->getShippingMethodRepository()->findOneBy(['code' => $shippingMethod]);

        return $method;
    }

    /**
     * Retrieve Shipping Mzthod Repository
     *
     * @return ShippingMethodRepositoryInterface
     */
    protected function getShippingMethodRepository(): ShippingMethodRepositoryInterface
    {
        /** @var ShippingMethodRepositoryInterface $shippingMethodRepository */
        $shippingMethodRepository = $this->get('sylius.repository.shipping_method');

        return $shippingMethodRepository;
    }

    /**
     * Retrieve default template for pickup list
     *
     * @return string
     */
    protected function getDefaultTemplate(): string
    {
        return '@MagentixSyliusPickupPlugin/checkout/SelectShipping/pickup/list.html.twig';
    }

    /**
     * Retrieve Current Cart
     *
     * @return OrderInterface
     */
    protected function getCurrentCart(): OrderInterface
    {
        return $this->getContext()->getCart();
    }

    /**
     * Retrieve Cart Context
     *
     * @return CartContextInterface
     */
    protected function getContext(): CartContextInterface
    {
        /** @var CartContextInterface $context */
        $context = $this->get('sylius.context.cart');

        return $context;
    }

    /**
     * Retrieve Order Repository
     *
     * @return OrderRepositoryInterface
     */
    protected function getOrderRepository(): OrderRepositoryInterface
    {
        /** @var OrderRepositoryInterface $orderRepository */
        $orderRepository = $this->get('sylius.repository.order');

        return $orderRepository;
    }

    /**
     * @return array|CountryInterface[]
     */
    private function getAvailableCountries(): array
    {
        $countries = Countries::getNames();

        /** @var CountryInterface[] $definedCountries */
        $definedCountries = $this->countryRepository->findAll();

        $availableCountries = [];

        foreach ($definedCountries as $country) {
            $availableCountries[$country->getCode()] = $countries[$country->getCode()];
        }

        return $availableCountries;
    }
}
