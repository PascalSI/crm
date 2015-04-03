<?php

namespace OroCRM\Bundle\MagentoBundle\Entity\Repository;

use DateTime;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;

use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\EntityBundle\Exception\InvalidEntityException;

use OroCRM\Bundle\MagentoBundle\Entity\Cart;
use OroCRM\Bundle\MagentoBundle\Entity\Customer;
use OroCRM\Bundle\MagentoBundle\Entity\Order;
use OroCRM\Bundle\MagentoBundle\Utils\DatePeriodUtils;

class OrderRepository extends EntityRepository
{
    /**
     * @param Cart|Customer $item
     * @param string        $field
     *
     * @return Cart|Customer|null $item
     * @throws InvalidEntityException
     */
    public function getLastPlacedOrderBy($item, $field)
    {
        if (!($item instanceof Cart) && !($item instanceof Customer)) {
            throw new InvalidEntityException();
        }
        $qb = $this->createQueryBuilder('o');
        $qb->where('o.' . $field . ' = :item');
        $qb->setParameter('item', $item);
        $qb->orderBy('o.updatedAt', 'DESC');
        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Get customer orders subtotal amount
     *
     * @param Customer $customer
     * @return float
     */
    public function getCustomerOrdersSubtotalAmount(Customer $customer)
    {
        $qb = $this->createQueryBuilder('o');

        $qb
            ->select('sum(o.subtotalAmount) as subtotal')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('o.customer', ':customer'),
                    $qb->expr()->neq($qb->expr()->lower('o.status'), ':status')
                )
            )
            ->setParameter('customer', $customer)
            ->setParameter('status', Order::STATUS_CANCELED);

        return (float)$qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param AclHelper $aclHelper
     * @return array
     */
    public function getAverageOrderAmount(AclHelper $aclHelper)
    {
        /** @var \DateTime $sliceDate */
        list($sliceDate, $monthMatch, $channelTemplate) = $this->getOrderSliceDateAndTemplates();

        /** @var EntityManager $entityManager */
        $entityManager = $this->getEntityManager();
        $channels      = $entityManager->getRepository('OroCRMChannelBundle:Channel')
            ->getAvailableChannelNames($aclHelper, 'magento');

        // prepare result template
        $result = [];
        foreach ($channels as $channel) {
            $channelId = $channel['id'];
            $channelName = $channel['name'];
            $result[$channelId] = ['name' => $channelName, 'data' => $channelTemplate];
        }

        // execute data query
        $queryBuilder = $this->createQueryBuilder('o');
        $selectClause = '
            IDENTITY(o.dataChannel) AS dataChannelId,
            MONTH(o.createdAt) as monthCreated,
            AVG(
                CASE WHEN o.subtotalAmount IS NOT NULL THEN o.subtotalAmount ELSE 0 END -
                CASE WHEN o.discountAmount IS NOT NULL THEN o.discountAmount ELSE 0 END
            ) as averageOrderAmount';
        $queryBuilder->select($selectClause)
            ->where('o.createdAt > :sliceDate')->setParameter('sliceDate', $sliceDate)
            ->groupBy('dataChannelId, monthCreated');
        $amountStatistics = $aclHelper->apply($queryBuilder)->execute();

        foreach ($amountStatistics as $row) {
            $channelId   = (int)$row['dataChannelId'];
            $month       = (int)$row['monthCreated'];
            $year        = $monthMatch[$month]['year'];
            $orderAmount = (float)$row['averageOrderAmount'];

            if (isset($result[$channelId]['data'][$year][$month])) {
                $result[$channelId]['data'][$year][$month] += $orderAmount;
            }
        }

        return $result;
    }

    /**
     * @param DateTime $from
     * @param DateTime $to
     *
     * @return array
     */
    public function getRevenueOverTime(DateTime $from, DateTime $to)
    {
        $qb = $this->createQueryBuilder('o');

        $orders = $qb
            ->select('YEAR(o.createdAt) AS yearCreated')
            ->addSelect('MONTH(o.createdAt) AS monthCreated')
            ->addSelect('DAY(o.createdAt) AS dayCreated')
            ->addSelect('SUM(
                    CASE WHEN o.subtotalAmount IS NOT NULL THEN o.subtotalAmount ELSE 0 END -
                    CASE WHEN o.discountAmount IS NOT NULL THEN o.discountAmount ELSE 0 END
                ) AS totalOrderAmount')
            ->addSelect('o.currency AS currency')
            ->andWhere($qb->expr()->between('o.createdAt', ':from', ':to'))
            ->setParameters([
                'from' => $from,
                'to'   => $to,
            ])
            ->groupBy('yearCreated, monthCreated, dayCreated')
            ->getQuery()
            ->getResult()
        ;

        $result = DatePeriodUtils::getDays($from, $to);
        foreach ($orders as $order) {
            $year  = $order['yearCreated'];
            $month = $order['monthCreated'];
            $day   = $order['dayCreated'];

            $result[$year][$month][$day] += $order['totalOrderAmount'];
        }

        return $result;
    }

    /**
     * @return array
     */
    protected function getOrderSliceDateAndTemplates()
    {
        // calculate slice date
        $currentYear  = (int)date('Y');
        $currentMonth = (int)date('m');

        $sliceYear  = $currentMonth === 12 ? $currentYear : $currentYear - 1;
        $sliceMonth = $currentMonth === 12 ? 1 : $currentMonth + 1;
        $sliceDate  = new \DateTime(sprintf('%s-%s-01', $sliceYear, $sliceMonth), new \DateTimeZone('UTC'));

        // calculate match for month and default channel template
        $monthMatch = [];
        $channelTemplate = [];
        if ($sliceYear !== $currentYear) {
            for ($i = $sliceMonth; $i <= 12; $i++) {
                $monthMatch[$i] = ['year' => $sliceYear, 'month' => $i];
                $channelTemplate[$sliceYear][$i] = 0;
            }
        }
        for ($i = 1; $i <= $currentMonth; $i++) {
            $monthMatch[$i] = ['year' => $currentYear, 'month' => $i];
            $channelTemplate[$currentYear][$i] = 0;
        }

        return [$sliceDate, $monthMatch, $channelTemplate];
    }
}
