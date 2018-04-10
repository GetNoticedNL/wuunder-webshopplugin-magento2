<?php
namespace Wuunder\Wuunderconnector\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;

class InstallSchema implements InstallSchemaInterface
{
    /**
     * install tables
     *
     * @param \Magento\Framework\Setup\SchemaSetupInterface $setup
     * @param \Magento\Framework\Setup\ModuleContextInterface $context
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();
        if (!$installer->tableExists('wuunder_shipment')) {
            $table = $installer->getConnection()->newTable(
                $installer->getTable('wuunder_shipment')
            )
                ->addColumn(
                    'shipment_id',
                    Table::TYPE_INTEGER,
                    null,
                    [
                        'identity' => true,
                        'nullable' => false,
                        'primary'  => true,
                        'unsigned' => true,
                    ],
                    'Shipment ID'
                )
                ->addColumn(
                    'order_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable => false'],
                    'Shipment Name'
                )
                ->addColumn(
                    'label_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable => true'],
                    'Shipment label id'
                )
                ->addColumn(
                    'label_url',
                    Table::TYPE_TEXT,
                    '64k',
                    ['nullable => true'],
                    'Shipment label url'
                )
                ->addColumn(
                    'tt_url',
                    Table::TYPE_TEXT,
                    '64k',
                    ['nullable => true'],
                    'Shipment T&T url'
                )
                ->addColumn(
                    'booking_url',
                    Table::TYPE_TEXT,
                    '64k',
                    ['nullable => true'],
                    'Shipment booking url'
                )
                ->addColumn(
                    'booking_token',
                    Table::TYPE_TEXT,
                    '64k',
                    ['nullable => false'],
                    'Shipment booking token'
                )
                ->setComment('Wuunder shipment table');
            $installer->getConnection()->createTable($table);

//            $installer->getConnection()->addIndex(
//                $installer->getTable('wuunder_shipment'),
//                $setup->getIdxName(
//                    $installer->getTable('wuunder_shipment'),
//                    ['name','url_key','post_content','tags','featured_image','sample_upload_file'],
//                    \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_FULLTEXT
//                ),
//                ['name','url_key','post_content','tags','featured_image','sample_upload_file'],
//                \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_FULLTEXT
//            );
        }
        $installer->endSetup();
    }
}
