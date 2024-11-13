<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_SmartStock extends Module
{
    public function __construct()
    {
        $this->name = 'ps_smartstock';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'SmartDev';
        $this->bootstrap = true;
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('SmartStock - Common Stock and Custom Deduction');
        $this->description = $this->l('Manages a common stock for product combinations and allows custom stock deduction.');

        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install() &&
            $this->addDatabaseColumns() &&
            $this->registerHook('actionUpdateQuantity');
    }

    /**
     * Ajouter les colonnes nécessaires en base de données
     */
    public function addDatabaseColumns()
    {
        $sqlQueries = [
            'ALTER TABLE `' . _DB_PREFIX_ . 'product` ADD `use_common_stock` TINYINT(1) NOT NULL DEFAULT 0',
            'ALTER TABLE `' . _DB_PREFIX_ . 'product_attribute` ADD `stock_deduction` INT(10) NOT NULL DEFAULT 1',
            'ALTER TABLE `' . _DB_PREFIX_ . 'product_attribute` ADD `use_common_stock` TINYINT(1) NOT NULL DEFAULT 0'
        ];

        foreach ($sqlQueries as $query) {
            if (!Db::getInstance()->execute($query)) {
                PrestaShopLogger::addLog('Erreur lors de l\'exécution de la requête SQL : ' . Db::getInstance()->getMsgError(), 3, null, 'Product', (int)$this->context->shop->id);

                return false;
            }
        }

        return true;
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            $this->removeDatabaseColumns();
    }

    /**
     * Supprimer les colonnes lors de la désinstallation
     */
    public function removeDatabaseColumns()
    {
        $sqlQueries = [
            'ALTER TABLE `' . _DB_PREFIX_ . 'product` DROP COLUMN `use_common_stock`',
            'ALTER TABLE `' . _DB_PREFIX_ . 'product_attribute` DROP COLUMN `stock_deduction`',
            'ALTER TABLE `' . _DB_PREFIX_ . 'product_attribute` DROP COLUMN `use_common_stock`'
        ];

        foreach ($sqlQueries as $query) {
            if (!Db::getInstance()->execute($query)) {
                PrestaShopLogger::addLog('Erreur lors de l\'exécution de la requête SQL : ' . Db::getInstance()->getMsgError(), 3, null, 'Product', (int)$this->context->shop->id);

                return false;
            }
        }

        return true;
    }

    public function hookActionUpdateQuantity($params)
    {
        $id_product = (int)$params['id_product'];
        $quantity = (int)$params['quantity'];

        // Vérifie si le stock commun est activé
        if ($this->isCommonStockEnabled($id_product)) {
            $this->updateStockForCombinations($id_product, $quantity);
        }
    }

    private function isCommonStockEnabled($id_product)
    {
        return (bool)Db::getInstance()->getValue('
            SELECT use_common_stock FROM ' . _DB_PREFIX_ . 'ps_smartstock
            WHERE id_product = ' . (int)$id_product
        );
    }

    private function updateStockForCombinations($id_product, $new_quantity)
    {
        // Récupère toutes les déclinaisons du produit
        $combinations = Db::getInstance()->executeS('
            SELECT id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute
            WHERE id_product = ' . (int)$id_product
        );

        // Mets à jour toutes les déclinaisons en une seule requête
        $combinationIds = array_column($combinations, 'id_product_attribute');

        if (!empty($combinationIds)) {
            Db::getInstance()->execute('
                UPDATE ' . _DB_PREFIX_ . 'stock_available
                SET quantity = ' . (int)$new_quantity . '
                WHERE id_product = ' . (int)$id_product . ' 
                AND id_product_attribute IN (' . implode(',', $combinationIds) . ')
            ');
        }
    }
    
    public function getProductsWithCombinations()
    {
        $sql = '
            SELECT p.id_product, pl.name, sa.quantity as stock, p.use_common_stock, i.id_image
            FROM ' . _DB_PREFIX_ . 'product p
            LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
            LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON p.id_product = sa.id_product
            LEFT JOIN ' . _DB_PREFIX_ . 'image i ON i.id_product = p.id_product AND i.cover = 1
            WHERE pl.id_lang = ' . (int)$this->context->language->id . '
            GROUP BY p.id_product';
        
        $products = Db::getInstance()->executeS($sql);
        
        foreach ($products as &$product) {
            // Ajoutez l'URL de l'image du produit
            if ($product['id_image']) {
                $product['image_link'] = $this->context->link->getImageLink($product['id_product'], $product['id_image'], 'home_default');
            } else {
                $product['image_link'] = ''; // Pas d'image disponible
            }
    
            // Récupérer les combinaisons du produit
            $product['combinations'] = $this->getProductCombinations($product['id_product']);
            
        }
    
        return $products;
    }

    public function getProductCombinations($id_product)
    {
        $sql = '
            SELECT pa.id_product_attribute, pa.stock_deduction, sa.quantity as stock, pa.use_common_stock,
                   al.name, pai.id_image
            FROM ' . _DB_PREFIX_ . 'product_attribute pa
            LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON pa.id_product_attribute = sa.id_product_attribute
            LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute_image pai ON pa.id_product_attribute = pai.id_product_attribute
            LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac ON pa.id_product_attribute = pac.id_product_attribute
            LEFT JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON pac.id_attribute = al.id_attribute AND al.id_lang = ' . (int)$this->context->language->id . '
            WHERE pa.id_product = ' . (int)$id_product . '
            GROUP BY pa.id_product_attribute, pa.stock_deduction, sa.quantity, pa.use_common_stock, al.name, pai.id_image';
        
        $combinations = Db::getInstance()->executeS($sql);
        
        foreach ($combinations as &$combination) {
            if ($combination['id_image']) {
                $combination['image_link'] = $this->context->link->getImageLink($id_product, $combination['id_image'], 'home_default');
            } else {
                $combination['image_link'] = ''; // Pas d'image disponible
            }
        }
        
        return $combinations;
    }
    
    public function renderForm()
    {
    // Récupération des produits avec combinaisons
    $products = $this->getProductsWithCombinations();

    // Parcours de chaque produit pour récupérer ses combinaisons
    foreach ($products as $product) {
            $combinations[$product['id_product']] = $this->getProductCombinations($product['id_product']);
    }
        $this->context->smarty->assign([
            'products' => $products,
            'combination' => $combinations,
            'confirmation', $this->l('Settings updated successfully.'),
            'current_url' => $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name,
        ]);

        //VARDUMP
        var_dump($combinations);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/ps_smartstock.tpl');
    }

    public function getContent()
    {
        if (Tools::isSubmit('submitSmartStock')) {
            $this->postProcess();
        }

        return $this->renderForm();
        
    }

    private function postProcess()
    {
        $products = Tools::getValue('products');
    
        if (is_array($products) && !empty($products)) {
            foreach ($products as $product) {
                // Mise à jour du champ use_common_stock pour le produit
                Db::getInstance()->update('product', [
                    'use_common_stock' => (int)(isset($product['use_common_stock']) && $product['use_common_stock']),
                ], 'id_product = ' . (int)$product['id_product']);
                Db::getInstance()->update('product_attribute', [
                    'use_common_stock' => (int)(isset($product['use_common_stock']) && $product['use_common_stock']),
                ], 'id_product = ' . (int)$product['id_product']);
    
                // Vérification si le produit a des déclinaisons
                if (isset($product['combinations']) && is_array($product['combinations'])) {
                    
                    foreach ($product['combinations'] as $combination) {
                        // Mise à jour de la valeur stock_deduction pour chaque déclinaison
                        Db::getInstance()->update('product_attribute', [
                            'stock_deduction' => (int)$combination['stock_deduction'],  // S'assurer que la valeur est bien castée
                        ], 'id_product_attribute = ' . (int)$combination);
                    }
                }
            }
        } else {
            // Gestion des erreurs si aucun produit n'a été reçu
            $this->context->smarty->assign('error', $this->l('No product data received.'));
        }
    }
}