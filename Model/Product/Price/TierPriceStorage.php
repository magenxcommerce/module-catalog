<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Model\Product\Price;

use Magento\Catalog\Api\Data\TierPriceInterface;
use Magento\Catalog\Api\TierPriceStorageInterface;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexerProcessor;
use Magento\Catalog\Model\Product\Price\Validation\TierPriceValidator;
use Magento\Catalog\Model\ProductIdLocatorInterface;

/**
 * Tier price storage.
 */
class TierPriceStorage implements TierPriceStorageInterface
{
    /**
     * Tier price resource model.
     *
     * @var TierPricePersistence
     */
    private $tierPricePersistence;

    /**
     * Tier price validator.
     *
     * @var TierPriceValidator
     */
    private $tierPriceValidator;

    /**
     * Tier price builder.
     *
     * @var TierPriceFactory
     */
    private $tierPriceFactory;

    /**
     * Price index processor.
     *
     * @var PriceIndexerProcessor
     */
    private $priceIndexProcessor;

    /**
     * Product ID locator.
     *
     * @var ProductIdLocatorInterface
     */
    private $productIdLocator;

    /**
     * @param TierPricePersistence $tierPricePersistence
     * @param TierPriceValidator $tierPriceValidator
     * @param TierPriceFactory $tierPriceFactory
     * @param PriceIndexerProcessor $priceIndexProcessor
     * @param ProductIdLocatorInterface $productIdLocator
     */
    public function __construct(
        TierPricePersistence $tierPricePersistence,
        TierPriceValidator $tierPriceValidator,
        TierPriceFactory $tierPriceFactory,
        PriceIndexerProcessor $priceIndexProcessor,
        ProductIdLocatorInterface $productIdLocator
    ) {
        $this->tierPricePersistence = $tierPricePersistence;
        $this->tierPriceValidator = $tierPriceValidator;
        $this->tierPriceFactory = $tierPriceFactory;
        $this->priceIndexProcessor = $priceIndexProcessor;
        $this->productIdLocator = $productIdLocator;
    }

    /**
     * @inheritdoc
     */
    public function get(array $skus)
    {
        $skus = $this->tierPriceValidator->validateSkus($skus);
        $skuByIdLookup = $this->buildSkuByIdLookup($skus);
        $prices = $this->getExistingPrices($skuByIdLookup);

        return $prices;
    }

    /**
     * @inheritdoc
     */
    public function update(array $prices)
    {
        $skus = array_unique(
            array_map(
                function (TierPriceInterface $price) {
                    return $price->getSku();
                },
                $prices
            )
        );
        $skuByIdLookup = $this->buildSkuByIdLookup($skus);
        $existingPrices = $this->getExistingPrices($skuByIdLookup, true);
        $result = $this->tierPriceValidator->retrieveValidationResult($prices, $existingPrices);
        $prices = $this->removeIncorrectPrices($prices, $result->getFailedRowIds());
        $formattedPrices = $this->retrieveFormattedPrices($prices);
        $this->tierPricePersistence->update($formattedPrices);
        $this->reindexPrices(array_keys($skuByIdLookup));

        return $result->getFailedItems();
    }

    /**
     * @inheritdoc
     */
    public function replace(array $prices)
    {
        $result = $this->tierPriceValidator->retrieveValidationResult($prices);
        $prices = $this->removeIncorrectPrices($prices, $result->getFailedRowIds());
        $affectedIds = $this->retrieveAffectedProductIdsForPrices($prices);
        $formattedPrices = $this->retrieveFormattedPrices($prices);
        $this->tierPricePersistence->replace($formattedPrices, $affectedIds);
        $this->reindexPrices($affectedIds);

        return $result->getFailedItems();
    }

    /**
     * @inheritdoc
     */
    public function delete(array $prices)
    {
        $affectedIds = $this->retrieveAffectedProductIdsForPrices($prices);
        $result = $this->tierPriceValidator->retrieveValidationResult($prices);
        $prices = $this->removeIncorrectPrices($prices, $result->getFailedRowIds());
        $priceIds = $this->retrieveAffectedPriceIds($prices);
        $this->tierPricePersistence->delete($priceIds);
        $this->reindexPrices($affectedIds);

        return $result->getFailedItems();
    }

    /**
     * Get existing prices by SKUs.
     *
     * @param array $skuByIdLookup
     * @param bool $groupBySku [optional]
     * @return array
     */
    private function getExistingPrices(array $skuByIdLookup, $groupBySku = false): array
    {
        $rawPrices = $this->tierPricePersistence->get(array_keys($skuByIdLookup));
        $prices = [];

        $linkField = $this->tierPricePersistence->getEntityLinkField();
        foreach ($rawPrices as $rawPrice) {
            $sku = $skuByIdLookup[$rawPrice[$linkField]];
            $price = $this->tierPriceFactory->create($rawPrice, $sku);
            if ($groupBySku) {
                $prices[$sku][] = $price;
            } else {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    /**
     * Retrieve formatted prices.
     *
     * @param array $prices
     * @return array
     */
    private function retrieveFormattedPrices(array $prices): array
    {
        $formattedPrices = [];

        foreach ($prices as $price) {
            $idsBySku = $this->productIdLocator->retrieveProductIdsBySkus([$price->getSku()]);
            $ids = array_keys($idsBySku[$price->getSku()]);
            foreach ($ids as $id) {
                $formattedPrices[] = $this->tierPriceFactory->createSkeleton($price, $id);
            }
        }

        return $formattedPrices;
    }

    /**
     * Retrieve affected product IDs for prices.
     *
     * @param TierPriceInterface[] $prices
     * @return array
     */
    private function retrieveAffectedProductIdsForPrices(array $prices): array
    {
        $skus = array_unique(
            array_map(
                function (TierPriceInterface $price) {
                    return $price->getSku();
                },
                $prices
            )
        );

        return $this->retrieveAffectedIds($skus);
    }

    /**
     * Retrieve affected product IDs.
     *
     * @param array $skus
     * @return array
     */
    private function retrieveAffectedIds(array $skus): array
    {
        $affectedIds = [];

        foreach ($this->productIdLocator->retrieveProductIdsBySkus($skus) as $productId) {
            $affectedIds = array_merge($affectedIds, array_keys($productId));
        }

        return array_unique($affectedIds);
    }

    /**
     * Retrieve affected price IDs.
     *
     * @param array $prices
     * @return array
     */
    private function retrieveAffectedPriceIds(array $prices): array
    {
        $affectedIds = $this->retrieveAffectedProductIdsForPrices($prices);
        $formattedPrices = $this->retrieveFormattedPrices($prices);
        $existingPrices = $this->tierPricePersistence->get($affectedIds);
        $priceIds = [];

        foreach ($formattedPrices as $price) {
            $priceIds[] = $this->retrievePriceId($price, $existingPrices);
        }

        return $priceIds;
    }

    /**
     * Look through provided price in list of existing prices and retrieve it's Id.
     *
     * @param array $price
     * @param array $existingPrices
     * @return int|null
     */
    private function retrievePriceId(array $price, array $existingPrices)
    {
        $linkField = $this->tierPricePersistence->getEntityLinkField();

        foreach ($existingPrices as $existingPrice) {
            if ($existingPrice['all_groups'] == $price['all_groups']
                && $existingPrice['customer_group_id'] == $price['customer_group_id']
                && $existingPrice['qty'] == $price['qty']
                && $this->isCorrectPriceValue($existingPrice, $price)
                && $existingPrice[$linkField] == $price[$linkField]
            ) {
                return $existingPrice['value_id'];
            }
        }

        return null;
    }

    /**
     * Check that price value or price percentage value is not equal to 0 and is not similar with existing value.
     *
     * @param array $existingPrice
     * @param array $price
     * @return bool
     */
    private function isCorrectPriceValue(array $existingPrice, array $price): bool
    {
        return ($existingPrice['value'] != 0 && $existingPrice['value'] == $price['value'])
            || ($existingPrice['percentage_value'] !== null
                && $existingPrice['percentage_value'] == $price['percentage_value']);
    }

    /**
     * Generate lookup to retrieve SKU by product ID.
     *
     * @param array $skus
     * @return array
     */
    private function buildSkuByIdLookup($skus): array
    {
        $lookup = [];
        foreach ($this->productIdLocator->retrieveProductIdsBySkus($skus) as $sku => $ids) {
            foreach (array_keys($ids) as $id) {
                $lookup[$id] = $sku;
            }
        }

        return $lookup;
    }

    /**
     * Reindex prices.
     *
     * @param array $ids
     * @return void
     */
    private function reindexPrices(array $ids)
    {
        if (!empty($ids)) {
            $this->priceIndexProcessor->reindexList($ids);
        }
    }

    /**
     * Remove prices from price list by id list.
     *
     * @param array $prices
     * @param array $ids
     * @return array
     */
    private function removeIncorrectPrices(array $prices, array $ids): array
    {
        foreach ($ids as $id) {
            unset($prices[$id]);
        }

        return $prices;
    }
}
