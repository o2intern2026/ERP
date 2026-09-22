<?php

namespace App\Modules\Orders\Services;

use App\Support\Contracts\ManifestParser;
use App\Support\Enums;
use DateTimeImmutable;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the shared dispatch manifest without adding a spreadsheet package to the frozen dependency set.
 *
 * CHANGE_REQUESTS #123 hardening ("要保证能够准确的转换客户订单信息"): the format is sniffed from the bytes (zip → XLSX, OLE → a clear
 * "旧版 XLS" message, anything else → text CSV), the CSV encoding is detected (UTF-8 BOM, UTF-16 LE / BE, GB18030) and the
 * delimiter sniffed (comma / semicolon / tab); full-width digits and punctuation are folded to half-width; headers accept the
 * common Chinese / English variants; weights and dimensions follow their header's unit (cm → mm, 单件重量 × 箱数 → line total);
 * phones, postcodes and states are normalised with a Chinese row-level message that names the column as the client sees it.
 * The ManifestParser contract shape is unchanged — every addition is a `warnings` entry or an extra key (`label`) on an entry.
 *
 * CHANGE_REQUESTS #136 (audit PORTAL-05 / PORTAL-07): every `errors` entry also carries `consignment_mark` (the refused row's mark
 * after carry-down) so the caller can block the whole mark instead of generating an order short of a goods line; rows carry
 * `deliver_to_address_type` from an optional 地址类型 / Address type column (住宅 → residential, 商业 → business, FBA → fba, empty →
 * null = the caller's default) so a residential consignee on a list reaches the tailgate rule like one typed on the order form.
 *
 * CHANGE_REQUESTS #143 (the client's English consolidation list, 拼箱清单, uploaded as is): waybill / Recipient / Postal Code /
 * Detailed Address / Commodity / 商品数量 / 每箱产品总价 / Cube(m3) aliases; a sheet WITHOUT a 箱数-type column reads every row as ONE
 * carton (file-level warning); when two columns map to the same field the FIRST (leftmost) wins — so "City" beats a misused "Suburb"
 * column; "Commodity" / "Description" / "Goods" land in description_cn when the text has Chinese and description_en otherwise;
 * "TTL VALUE(AUD)" is the per-unit price when 每箱产品总价 × 商品数量 agrees with it, the line total when it stands alone. Grouping
 * (by mark / by recipient) and the address-type default are IMPORT OPTIONS of OrderImportService, never Row fields.
 */
final class SpreadsheetManifestParser implements ManifestParser
{
    private const MAX_ROWS = 5000;

    /**
     * Normalised header text (normaliseHeader) → row field. First match per field wins, left to right (CHANGE_REQUESTS #143: so when a
     * sheet carries both "City" and a misused "Suburb" column, the leftmost — City — is the suburb and the other is ignored).
     * `description_auto` and `value_aud` are resolver fields (never Row keys): a 品名 column whose language decides cn / en, and a
     * value column that is the unit price or the line total depending on what else the sheet carries (convertRow).
     */
    private const HEADERS = [
        'mark' => 'consignment_mark', 'marks' => 'consignment_mark', 'shippingmark' => 'consignment_mark', 'consignmentmark' => 'consignment_mark',
        '唛头' => 'consignment_mark', '麦头' => 'consignment_mark', '嘜頭' => 'consignment_mark',
        // CHANGE_REQUESTS #143: a consolidation list has a waybill per carton instead of a 唛头.
        'channelwaybillnumber' => 'consignment_mark', 'waybill' => 'consignment_mark', 'waybillno' => 'consignment_mark', 'waybillnumber' => 'consignment_mark',
        '运单号' => 'consignment_mark', '转单号' => 'consignment_mark', '运单编号' => 'consignment_mark',
        'chinesename' => 'description_cn', '中文品名' => 'description_cn', '品名' => 'description_cn', '货物名称' => 'description_cn', '货名' => 'description_cn', '货物' => 'description_cn',
        'englishname' => 'description_en', '英文品名' => 'description_en', 'goodsdescription' => 'description_en', 'item' => 'description_en', 'product' => 'description_en',
        // CHANGE_REQUESTS #143: language decides the slot — Chinese text → description_cn, anything else → description_en.
        'description' => 'description_auto', 'commodity' => 'description_auto', 'goods' => 'description_auto', 'commodityname' => 'description_auto', 'goodsname' => 'description_auto',
        'hscode' => 'hs_code', '海关编码' => 'hs_code', 'hs编码' => 'hs_code',
        'material' => 'material', '材质' => 'material',
        'use' => 'usage', 'usage' => 'usage', '用途' => 'usage',
        'brand' => 'brand', '品牌' => 'brand',
        'typeofpackaging' => 'package_type', 'packaging' => 'package_type', 'packagetype' => 'package_type', 'packing' => 'package_type',
        '外包装种类' => 'package_type', '包装类型' => 'package_type', '包装' => 'package_type', '包装方式' => 'package_type', '包装种类' => 'package_type',
        'cartonqty' => 'carton_qty', 'cartons' => 'carton_qty', 'carton' => 'carton_qty', 'ctns' => 'carton_qty', 'ctn' => 'carton_qty',
        'boxes' => 'carton_qty', 'noofcartons' => 'carton_qty', 'cartonquantity' => 'carton_qty', '箱数' => 'carton_qty', '总箱数' => 'carton_qty', '箱' => 'carton_qty',
        'productquantity' => 'unit_qty', 'pcs' => 'unit_qty', 'pieces' => 'unit_qty', 'units' => 'unit_qty', 'unitqty' => 'unit_qty', 'qty' => 'unit_qty', 'quantity' => 'unit_qty',
        '产品数量' => 'unit_qty', '件数' => 'unit_qty', '总件数' => 'unit_qty', '每箱数量' => 'unit_qty', '数量' => 'unit_qty', '商品数量' => 'unit_qty', // #143
        'unitpriceaud' => 'unit_price_cents', 'unitprice' => 'unit_price_cents', '单价澳元' => 'unit_price_cents', '单价' => 'unit_price_cents',
        'totalpriceaud' => 'total_price_cents', 'totalprice' => 'total_price_cents', '总价澳元' => 'total_price_cents', '总价' => 'total_price_cents',
        // CHANGE_REQUESTS #143: the carton total of the consolidation list; "TTL VALUE(AUD)" is resolved against it (unit price when consistent, else ignored).
        '每箱产品总价aud' => 'total_price_cents', '每箱产品总价' => 'total_price_cents', 'totalvalue' => 'total_price_cents', 'totalvalueaud' => 'total_price_cents', 'cartonvalue' => 'total_price_cents', 'cartonvalueaud' => 'total_price_cents',
        'ttlvalueaud' => 'value_aud', 'ttlvalue' => 'value_aud', 'valueaud' => 'value_aud', 'declaredvalue' => 'value_aud', 'declaredvalueaud' => 'value_aud', '货值' => 'value_aud', '申报价值' => 'value_aud',
        // Weight of the whole line (the order-line semantics, orders.lines.hint).
        'weightkg' => 'actual_weight_kg', 'weight' => 'actual_weight_kg', 'weightkgs' => 'actual_weight_kg', 'totalweight' => 'actual_weight_kg', 'totalweightkg' => 'actual_weight_kg',
        'grossweight' => 'actual_weight_kg', 'grossweightkg' => 'actual_weight_kg', 'gw' => 'actual_weight_kg', 'gwkg' => 'actual_weight_kg', 'kg' => 'actual_weight_kg', 'kgs' => 'actual_weight_kg',
        '实重kg' => 'actual_weight_kg', '实重' => 'actual_weight_kg', '重量' => 'actual_weight_kg', '重量kg' => 'actual_weight_kg', '毛重' => 'actual_weight_kg', '毛重kg' => 'actual_weight_kg',
        '总重' => 'actual_weight_kg', '总重kg' => 'actual_weight_kg', '总重量' => 'actual_weight_kg', '总重量kg' => 'actual_weight_kg', '总毛重' => 'actual_weight_kg', '总毛重kg' => 'actual_weight_kg', '公斤' => 'actual_weight_kg',
        // Weight per carton — multiplied by 箱数 into the line total, with a warning that says so.
        'unitweight' => 'unit_weight_kg', 'unitweightkg' => 'unit_weight_kg', 'weightpercarton' => 'unit_weight_kg', 'weightperctn' => 'unit_weight_kg', 'weightperbox' => 'unit_weight_kg',
        'kgperctn' => 'unit_weight_kg', 'kgpercarton' => 'unit_weight_kg', 'kg箱' => 'unit_weight_kg', 'cartonweight' => 'unit_weight_kg', 'cartonweightkg' => 'unit_weight_kg',
        '单件重量' => 'unit_weight_kg', '单件重量kg' => 'unit_weight_kg', '单箱重量' => 'unit_weight_kg', '单箱重量kg' => 'unit_weight_kg', '单箱毛重' => 'unit_weight_kg', '单箱毛重kg' => 'unit_weight_kg',
        '每箱重量' => 'unit_weight_kg', '每箱重量kg' => 'unit_weight_kg', '单重' => 'unit_weight_kg', '单重kg' => 'unit_weight_kg', '单件毛重' => 'unit_weight_kg', '单件毛重kg' => 'unit_weight_kg',
        'lengthcm' => 'length_mm', 'lengthmm' => 'length_mm', 'length' => 'length_mm', 'lcm' => 'length_mm', 'lmm' => 'length_mm',
        '长cm' => 'length_mm', '长mm' => 'length_mm', '长' => 'length_mm', '长度' => 'length_mm', '长度cm' => 'length_mm', '长度mm' => 'length_mm',
        'widthcm' => 'width_mm', 'widthmm' => 'width_mm', 'width' => 'width_mm', 'wcm' => 'width_mm', 'wmm' => 'width_mm',
        '宽cm' => 'width_mm', '宽mm' => 'width_mm', '宽' => 'width_mm', '宽度' => 'width_mm', '宽度cm' => 'width_mm', '宽度mm' => 'width_mm',
        'heightcm' => 'height_mm', 'heightmm' => 'height_mm', 'height' => 'height_mm', 'hcm' => 'height_mm', 'hmm' => 'height_mm',
        '高cm' => 'height_mm', '高mm' => 'height_mm', '高' => 'height_mm', '高度' => 'height_mm', '高度cm' => 'height_mm', '高度mm' => 'height_mm',
        'totalm³' => 'cbm', 'totalm3' => 'cbm', 'cbm' => 'cbm', 'volume' => 'cbm', 'totalcbm' => 'cbm', 'volumecbm' => 'cbm', 'm³' => 'cbm', 'm3' => 'cbm',
        'cubem3' => 'cbm', 'cubem³' => 'cbm', 'cube' => 'cbm', 'cubicmetres' => 'cbm', 'cubicmeters' => 'cbm', // #143
        '总方数' => 'cbm', '方数' => 'cbm', '体积' => 'cbm', '总体积' => 'cbm', '立方' => 'cbm',
        'consigneename' => 'deliver_to_name', 'consignee' => 'deliver_to_name', 'receiver' => 'deliver_to_name', 'receivername' => 'deliver_to_name', 'recipient' => 'deliver_to_name',
        'recipientname' => 'deliver_to_name', 'recipientsname' => 'deliver_to_name', // #143
        'deliverto' => 'deliver_to_name', 'shipto' => 'deliver_to_name', 'shiptoname' => 'deliver_to_name', 'attention' => 'deliver_to_name', 'attn' => 'deliver_to_name', 'contactname' => 'deliver_to_name',
        '收件人公司名人名' => 'deliver_to_name', '收件人' => 'deliver_to_name', '收货人' => 'deliver_to_name', '收件企业' => 'deliver_to_name', '收件公司' => 'deliver_to_name', '收货公司' => 'deliver_to_name',
        '收件人公司' => 'deliver_to_name', '收件企业联系人' => 'deliver_to_name', '收件人名称' => 'deliver_to_name', '收货人名称' => 'deliver_to_name', '收货方' => 'deliver_to_name', '收件方' => 'deliver_to_name', '联系人' => 'deliver_to_name',
        'contact' => 'deliver_to_phone', 'phone' => 'deliver_to_phone', 'tel' => 'deliver_to_phone', 'telephone' => 'deliver_to_phone', 'mobile' => 'deliver_to_phone', 'phoneno' => 'deliver_to_phone',
        'phonenumber' => 'deliver_to_phone', 'contactnumber' => 'deliver_to_phone', 'contactphone' => 'deliver_to_phone', 'contactno' => 'deliver_to_phone', 'telno' => 'deliver_to_phone',
        'recipientsphonenumber' => 'deliver_to_phone', 'recipientphonenumber' => 'deliver_to_phone', 'recipientphone' => 'deliver_to_phone', 'recipientsphone' => 'deliver_to_phone', 'receiverphone' => 'deliver_to_phone', 'consigneephone' => 'deliver_to_phone', // #143
        '联系方式' => 'deliver_to_phone', '电话' => 'deliver_to_phone', '联系电话' => 'deliver_to_phone', '手机' => 'deliver_to_phone', '手机号' => 'deliver_to_phone', '手机号码' => 'deliver_to_phone',
        '收件人电话' => 'deliver_to_phone', '收货人电话' => 'deliver_to_phone', '电话号码' => 'deliver_to_phone', '收件电话' => 'deliver_to_phone',
        'address' => 'deliver_to_address', 'deliveryaddress' => 'deliver_to_address', 'consigneeaddress' => 'deliver_to_address', 'shippingaddress' => 'deliver_to_address', 'shiptoaddress' => 'deliver_to_address',
        'street' => 'deliver_to_address', 'streetaddress' => 'deliver_to_address', 'addressline' => 'deliver_to_address', 'address1' => 'deliver_to_address',
        'detailedaddress' => 'deliver_to_address', 'fulladdress' => 'deliver_to_address', 'recipientaddress' => 'deliver_to_address', 'recipientsaddress' => 'deliver_to_address', // #143
        '收件人地址' => 'deliver_to_address', '地址' => 'deliver_to_address', '收件地址' => 'deliver_to_address', '收货地址' => 'deliver_to_address', '送货地址' => 'deliver_to_address', '派送地址' => 'deliver_to_address',
        '详细地址' => 'deliver_to_address', '收货人地址' => 'deliver_to_address', '街道地址' => 'deliver_to_address',
        'suburb' => 'deliver_to_suburb', 'city' => 'deliver_to_suburb', 'town' => 'deliver_to_suburb', 'suburbcity' => 'deliver_to_suburb', 'citysuburb' => 'deliver_to_suburb',
        '城区' => 'deliver_to_suburb', '城市' => 'deliver_to_suburb', '郊区' => 'deliver_to_suburb', '所在城市' => 'deliver_to_suburb', '区域' => 'deliver_to_suburb',
        'state' => 'deliver_to_state', 'province' => 'deliver_to_state', 'stateprovince' => 'deliver_to_state', 'region' => 'deliver_to_state',
        '州' => 'deliver_to_state', '省州' => 'deliver_to_state', '州省' => 'deliver_to_state', '省份' => 'deliver_to_state', '所在州' => 'deliver_to_state', '省' => 'deliver_to_state',
        'postcode' => 'deliver_to_postcode', 'postalcode' => 'deliver_to_postcode', 'zip' => 'deliver_to_postcode', 'zipcode' => 'deliver_to_postcode', 'postcodezip' => 'deliver_to_postcode',
        '邮编' => 'deliver_to_postcode', '邮政编码' => 'deliver_to_postcode', '邮码' => 'deliver_to_postcode', '邮政编号' => 'deliver_to_postcode',
        'fbareference' => 'fba_reference', 'fbashipmentid' => 'fba_reference', 'fba' => 'fba_reference', 'fbaid' => 'fba_reference', 'fbaref' => 'fba_reference', 'fbashipment' => 'fba_reference',
        'shipmentid' => 'fba_reference', 'reference' => 'fba_reference', 'ref' => 'fba_reference', 'desc' => 'fba_reference',
        'fba参考号' => 'fba_reference', 'fba货件编号' => 'fba_reference', 'fba货件号' => 'fba_reference', 'fba编号' => 'fba_reference', 'fba号' => 'fba_reference', '参考号' => 'fba_reference', '备注' => 'fba_reference',
        'externalref' => 'external_ref', 'customerref' => 'external_ref', 'customerreference' => 'external_ref', 'yourref' => 'external_ref', 'yourreference' => 'external_ref',
        'po' => 'external_ref', 'pono' => 'external_ref', 'ponumber' => 'external_ref', 'orderno' => 'external_ref', 'ordernumber' => 'external_ref',
        '客户参考号' => 'external_ref', '订单号' => 'external_ref', '客户订单号' => 'external_ref', 'po号' => 'external_ref', '采购单号' => 'external_ref',
        'requesteddate' => 'requested_date', 'deliverydate' => 'requested_date', 'requesteddeliverydate' => 'requested_date', 'requireddate' => 'requested_date', 'deliverby' => 'requested_date',
        '要求送达日' => 'requested_date', '要求送达日期' => 'requested_date', '送达日期' => 'requested_date', '送货日期' => 'requested_date', '要求送货日' => 'requested_date', '要求送货日期' => 'requested_date', '派送日期' => 'requested_date',
        'servicelevel' => 'service_level', 'service' => 'service_level', '服务等级' => 'service_level', '服务级别' => 'service_level', '时效' => 'service_level',
        'storagetier' => 'storage_tier', '存储等级' => 'storage_tier', '存储要求' => 'storage_tier', '库位等级' => 'storage_tier', // CHANGE_REQUESTS #126
        // CHANGE_REQUESTS #136: the consignee's address type — optional; an empty cell leaves the caller's default (address book / FBA reference / business).
        'addresstype' => 'deliver_to_address_type', 'deliveryaddresstype' => 'deliver_to_address_type', 'consigneeaddresstype' => 'deliver_to_address_type', 'consigneetype' => 'deliver_to_address_type',
        'residential' => 'deliver_to_address_type', 'residentialaddress' => 'deliver_to_address_type',
        '地址类型' => 'deliver_to_address_type', '收件类型' => 'deliver_to_address_type', '收件地址类型' => 'deliver_to_address_type', '收货地址类型' => 'deliver_to_address_type', '收件人类型' => 'deliver_to_address_type',
    ];

    /** 地址类型 cell → OrderEnums::ADDRESS_TYPES (CHANGE_REQUESTS #136); an empty cell keeps the caller's default, anything else is a row error. */
    private const ADDRESS_TYPE_VALUES = [
        'residential' => 'residential', 'home' => 'residential', 'house' => 'residential', 'residence' => 'residential',
        '住宅' => 'residential', '住宅地址' => 'residential', '民宅' => 'residential', '家庭' => 'residential', '家庭地址' => 'residential',
        'business' => 'business', 'company' => 'business', 'commercial' => 'business', 'office' => 'business',
        '商业' => 'business', '商业地址' => 'business', '公司' => 'business', '公司地址' => 'business', '企业' => 'business', '企业地址' => 'business',
        'fba' => 'fba', 'amazon' => 'fba', 'amazonfba' => 'fba', 'fba仓库' => 'fba', '亚马逊' => 'fba', '亚马逊仓库' => 'fba', '亚马逊fba' => 'fba',
    ];

    /** 存储等级 cell → contracts/enums.md storage tier (CHANGE_REQUESTS #126); an empty cell is 标准, anything else is a row error. */
    private const STORAGE_TIER_VALUES = [
        'bottom' => 'bottom', '底层' => 'bottom', '最底层' => 'bottom', '地面' => 'bottom',
        'standard' => 'standard', '标准' => 'standard',
    ];

    /**
     * Headers that count cartons only when the sheet has no dedicated 箱数 column: "No." is a serial number next to a 箱数 column
     * but the carton count on the original 《需派送货物清单》; 数量 / Qty / 件数 are unit counts when 箱数 exists. Leftmost wins.
     */
    private const CARTON_FALLBACKS = ['no', 'qty', 'quantity', '数量', '件数', '总件数', 'pieces'];

    /** Fields carried down blank cells from the previous row of the same mark (merged cells in the client's sheet). */
    private const CARRIED = ['consignment_mark', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'deliver_to_address_type', 'fba_reference'];

    /** State spellings → contracts/enums.md code. Lower case, spaces / dots removed, a trailing 州 / 省 stripped before the second lookup. */
    private const STATE_ALIASES = [
        'vic' => 'VIC', 'victoria' => 'VIC', '维多利亚' => 'VIC', '维州' => 'VIC', '维多利亚州' => 'VIC',
        'nsw' => 'NSW', 'newsouthwales' => 'NSW', '新南威尔士' => 'NSW', '新南威尔斯' => 'NSW', '新州' => 'NSW',
        'qld' => 'QLD', 'queensland' => 'QLD', '昆士兰' => 'QLD', '昆州' => 'QLD',
        'sa' => 'SA', 'southaustralia' => 'SA', '南澳' => 'SA', '南澳大利亚' => 'SA', '南澳洲' => 'SA',
        'wa' => 'WA', 'westernaustralia' => 'WA', '西澳' => 'WA', '西澳大利亚' => 'WA', '西澳洲' => 'WA',
        'tas' => 'TAS', 'tasmania' => 'TAS', '塔斯马尼亚' => 'TAS', '塔州' => 'TAS', '塔斯曼尼亚' => 'TAS',
        'nt' => 'NT', 'northernterritory' => 'NT', '北领地' => 'NT', '北部地区' => 'NT', '北领地区' => 'NT',
        'act' => 'ACT', 'australiancapitalterritory' => 'ACT', '首都领地' => 'ACT', '首都地区' => 'ACT', '澳大利亚首都领地' => 'ACT', '堪培拉' => 'ACT',
    ];

    /** Package words → OrderEnums::PACKAGE_TYPES code; anything else is kept as typed (OrderEnums::packageTypeLabel falls back to the raw value). */
    private const PACKAGE_TYPES = [
        'carton' => 'carton', 'cartons' => 'carton', 'ctn' => 'carton', 'ctns' => 'carton', 'box' => 'carton', 'boxes' => 'carton', 'case' => 'carton', 'cases' => 'carton',
        '纸箱' => 'carton', '箱' => 'carton', '纸盒' => 'carton', '纸箱包装' => 'carton', '外箱' => 'carton',
        'pallet' => 'pallet', 'pallets' => 'pallet', 'plt' => 'pallet', 'plts' => 'pallet', '托盘' => 'pallet', '卡板' => 'pallet', '托' => 'pallet', '整托' => 'pallet',
        'satchel' => 'satchel', 'satchels' => 'satchel', 'bag' => 'satchel', 'bags' => 'satchel', '袋' => 'satchel', '快递袋' => 'satchel', '编织袋' => 'satchel', '袋装' => 'satchel',
        'crate' => 'crate', 'crates' => 'crate', 'woodencrate' => 'crate', 'woodencase' => 'crate', '木箱' => 'crate', '木架' => 'crate', '木框' => 'crate',
        'tube' => 'tube', 'tubes' => 'tube', 'roll' => 'tube', '圆筒' => 'tube', '筒' => 'tube', '卷' => 'tube',
        'flatpack' => 'flat_pack', '平板包装' => 'flat_pack', '平板' => 'flat_pack',
        'skid' => 'skid', 'skids' => 'skid', '栈板' => 'skid',
    ];

    private const SERVICE_LEVELS = [
        'standard' => 'standard', 'std' => 'standard', 'normal' => 'standard', '标准' => 'standard', '普通' => 'standard', '标准件' => 'standard',
        'express' => 'express', 'urgent' => 'express', 'priority' => 'express', '加急' => 'express', '快递' => 'express', '特快' => 'express', '紧急' => 'express',
        'sameday' => 'same_day', '当日' => 'same_day', '当日送达' => 'same_day', '当天' => 'same_day', '当天送达' => 'same_day',
    ];

    /**
     * The canonical fields a manual form posts per row (CHANGE_REQUESTS #128 手工建立入库清单) — the same fields the CSV template carries,
     * keyed by the parser's own names. Every one of them counts as a present column for fromRows(): the dimensions are mm (no cm
     * header), the weight is the line total (no 单件重量 column) and an empty 存储等级 is 标准 without being a declaration.
     */
    public const FORM_FIELDS = [
        'consignment_mark', 'description_cn', 'description_en', 'package_type', 'carton_qty', 'unit_qty', 'actual_weight_kg',
        'length_mm', 'width_mm', 'height_mm', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb',
        'deliver_to_state', 'deliver_to_postcode', 'deliver_to_address_type', 'fba_reference', 'external_ref', 'requested_date', 'service_level', 'storage_tier',
    ];

    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Manifest file does not exist: {$path}");
        }

        $warnings = [];
        try {
            ['matrix' => $matrix, 'error' => $error] = $this->read($path, $warnings);
        } catch (InvalidArgumentException) {
            return $this->failure('corrupted_file', $warnings);
        }

        if ($error !== null) {
            return $this->failure($error, $warnings);
        }

        return $this->normalise($matrix, $warnings);
    }

    /**
     * CHANGE_REQUESTS #128 手工建立入库清单: rows typed on a form instead of read from a sheet. Each posted row is keyed by FORM_FIELDS
     * (unknown keys ignored, missing keys empty), numbered by its position 1..n, and goes through EXACTLY the normalisation and
     * validation parse() applies to a sheet row — cleaning, carry-down of blank consignee cells under the same 唛头, duplicate-row
     * warning, phone / postcode / state / package / date / 存储等级 rules — with the same Chinese `第 N 行「列名」…` messages, the column
     * named by `orders.imports.columns.*`. Blank rows are skipped but keep their number, as on a sheet. contracts/services.md §8.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows:list<array<string, mixed>>, errors:list<array<string, mixed>>, warnings:list<array<string, mixed>>, raw_rows:list<array<string, mixed>>}
     */
    public function fromRows(array $rows): array
    {
        $columns = array_flip(self::FORM_FIELDS);
        $headers = array_map(fn (string $field): string => __('orders.imports.columns.'.$field), self::FORM_FIELDS);
        $label = fn (string $field): string => $headers[$columns[$field] ?? -1] ?? __('orders.imports.columns.'.$field);
        $unitWeightUsed = false;

        $out = [];
        $errors = [];
        $warnings = [];
        $rawRows = [];
        $carry = [];
        $seen = [];

        foreach (array_values($rows) as $position => $posted) {
            $rowNumber = $position + 1;
            $posted = is_array($posted) ? $posted : [];
            $cells = array_map(fn (string $field): ?string => $this->clean($this->scalar($posted[$field] ?? null)), self::FORM_FIELDS);
            if ($this->isEmpty($cells)) {
                continue;
            }

            $raw = array_combine($headers, $cells);
            $rawRows[] = ['row' => $rowNumber, 'raw_json' => $raw];

            $signature = md5(implode("\x1F", array_map(fn ($value) => (string) $value, $cells)));
            if (isset($seen[$signature])) {
                $warnings[] = $this->entry($rowNumber, 'consignment_mark', $label('consignment_mark'), __('orders.imports.warnings.duplicate_row', ['row' => $rowNumber, 'first' => $seen[$signature]]));
            } else {
                $seen[$signature] = $rowNumber;
            }

            $mapped = array_combine(self::FORM_FIELDS, $cells);
            $this->carryDown($mapped, $carry);

            $converted = $this->convertRow($rowNumber, $mapped, $raw, $headers, $columns, $label, $unitWeightUsed);
            array_push($warnings, ...$converted['warnings']);
            if ($converted['errors'] !== []) {
                array_push($errors, ...$converted['errors']);

                continue;
            }
            $out[] = $converted['row'];
        }

        return [
            'rows' => $out,
            'errors' => $errors,
            'warnings' => array_merge($warnings, $this->consistencyWarnings($out)),
            'raw_rows' => $rawRows,
        ];
    }

    /**
     * Pick the reader from the bytes, not the extension: a renamed XLSX still opens, a real BIFF XLS gets a message naming the fix.
     *
     * @param  list<array{row:int, column:string, message:string}>  $warnings
     * @return array{matrix:?list<list<string|null>>, error:?string}
     */
    private function read(string $path, array &$warnings): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (str_starts_with($head, "PK\x03\x04")) {
            return ['matrix' => $this->readXlsx($path), 'error' => null];
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            return ['matrix' => null, 'error' => 'xls_unsupported'];
        }
        if ($extension === 'xlsx') {
            throw new InvalidArgumentException("Manifest workbook is not a zip container: {$path}");
        }
        if (in_array($extension, ['csv', 'txt', 'tsv', 'xls'], true)) {
            return ['matrix' => $this->readCsv($path, $warnings), 'error' => null];
        }

        return ['matrix' => null, 'error' => 'unsupported_file'];
    }

    /** @param list<array{row:int, column:string, message:string}> $warnings */
    private function failure(string $key, array $warnings): array
    {
        return [
            'rows' => [],
            'errors' => [['row' => 0, 'column' => 'file', 'label' => __('orders.imports.columns.file'), 'message' => __('orders.imports.errors.'.$key)]],
            'warnings' => $warnings,
            'raw_rows' => [],
        ];
    }

    /**
     * @param  list<array{row:int, column:string, message:string}>  $warnings
     * @return list<list<string|null>>
     */
    private function readCsv(string $path, array &$warnings): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException("Manifest file cannot be opened: {$path}");
        }

        [$text, $encoding] = $this->decode($raw);
        if ($encoding !== 'UTF-8') {
            $warnings[] = ['row' => 0, 'column' => 'file', 'label' => __('orders.imports.columns.file'), 'message' => __('orders.imports.warnings.encoding', ['encoding' => $encoding])];
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $delimiter = $this->sniffDelimiter($text);
        if ($delimiter !== ',') {
            $warnings[] = ['row' => 0, 'column' => 'file', 'label' => __('orders.imports.columns.file'), 'message' => __('orders.imports.warnings.delimiter', ['delimiter' => $delimiter === "\t" ? 'Tab' : $delimiter])];
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($value) => $this->clean($value === null ? null : (string) $value), $row);
            if (count($rows) > self::MAX_ROWS + 10) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * BOM first (UTF-8 / UTF-16 LE / UTF-16 BE — Excel's "Unicode Text"), then BOM-less UTF-16 by its NUL pattern, then valid
     * UTF-8, else GB18030 (Chinese Excel's plain "CSV", a superset of GBK). Never leaves mojibake in the headers.
     *
     * @return array{string, string}
     */
    private function decode(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [substr($raw, 3), 'UTF-8'];
        }
        if (str_starts_with($raw, "\xFF\xFE")) {
            return [$this->convert(substr($raw, 2), 'UTF-16LE'), 'UTF-16LE'];
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return [$this->convert(substr($raw, 2), 'UTF-16BE'), 'UTF-16BE'];
        }

        $sample = substr($raw, 0, 4096);
        if (strlen($sample) > 1 && substr_count($sample, "\0") > strlen($sample) / 4) {
            $encoding = $sample[1] === "\0" ? 'UTF-16LE' : 'UTF-16BE';

            return [$this->convert($raw, $encoding), $encoding];
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return [$raw, 'UTF-8'];
        }

        return [$this->convert($raw, 'GB18030'), 'GB18030'];
    }

    private function convert(string $raw, string $from): string
    {
        $text = mb_convert_encoding($raw, 'UTF-8', $from);
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException("Manifest text cannot be decoded from {$from}.");
        }

        return $text;
    }

    /** The separator that appears most on the first lines (header cells rarely contain commas); comma on a tie. */
    private function sniffDelimiter(string $text): string
    {
        $lines = array_slice(array_values(array_filter(explode("\n", $text, 12), fn ($line) => trim($line) !== '')), 0, 3);
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        foreach ($lines as $line) {
            foreach ($counts as $candidate => $count) {
                $counts[$candidate] = $count + substr_count($line, $candidate);
            }
        }
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > $counts[','] ? $best : ',';
    }

    /** @return list<list<string|null>> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException("Manifest workbook cannot be opened: {$path}");
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new InvalidArgumentException('Manifest workbook has no first worksheet.');
        }

        $shared = $sharedXml === false ? [] : $this->sharedStrings($sharedXml);
        $sheet = new SimpleXMLElement($sheetXml);
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = [];

        foreach ($sheet->children($namespace)->sheetData->row as $row) {
            // Excel omits empty rows from the XML: place each row at its own number so row numbers match what the client sees.
            $rowNumber = (int) $row->attributes()->r;
            while ($rowNumber > 0 && count($rows) < $rowNumber - 1) {
                $rows[] = [];
            }

            $values = [];
            foreach ($row->children($namespace)->c as $cell) {
                $attributes = $cell->attributes();
                $reference = (string) $attributes->r;
                preg_match('/^[A-Z]+/', $reference, $match);
                $index = $this->columnIndex($match[0] ?? 'A');
                $type = (string) $attributes->t;

                if ($type === 'inlineStr') {
                    $parts = $cell->xpath('.//*[local-name()="t"]') ?: [];
                    $value = implode('', array_map(fn ($part) => (string) $part, $parts));
                } elseif ($type === 's') {
                    $value = $shared[(int) $cell->children($namespace)->v] ?? null;
                } else {
                    $value = isset($cell->children($namespace)->v) ? (string) $cell->children($namespace)->v : null;
                }

                $values[$index] = $this->clean($value);
            }

            $last = $values === [] ? -1 : max(array_keys($values));
            $rows[] = $last < 0 ? [] : array_map(fn ($index) => $values[$index] ?? null, range(0, $last));
            if (count($rows) > self::MAX_ROWS + 10) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function sharedStrings(string $xml): array
    {
        $root = new SimpleXMLElement($xml);
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $values = [];
        foreach ($root->children($namespace)->si as $item) {
            $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $values[] = implode('', array_map(fn ($part) => (string) $part, $parts));
        }

        return $values;
    }

    /**
     * @param  list<list<string|null>>  $matrix
     * @param  list<array{row:int, column:string, message:string}>  $warnings
     * @return array<string, mixed>
     */
    private function normalise(array $matrix, array $warnings): array
    {
        [$headerIndex, $columns] = $this->locateHeaders($matrix);
        // CHANGE_REQUESTS #143: only the mark (唛头 / waybill) column is required; a sheet without a 箱数-type column is one carton per row.
        if ($headerIndex === null || ! isset($columns['consignment_mark'])) {
            return $this->failure('headers_missing', $warnings);
        }

        $headers = $matrix[$headerIndex];
        $labels = [];
        foreach ($columns as $field => $index) {
            $labels[$field] = $this->clean($headers[$index] ?? null) ?? __('orders.imports.columns.'.$field);
        }
        $label = fn (string $field): string => $labels[$field] ?? __('orders.imports.columns.'.$field);
        $unitWeightHeader = isset($columns['unit_weight_kg']) ? $label('unit_weight_kg') : null;
        $unitWeightUsed = false;
        if (! isset($columns['carton_qty'])) {
            $warnings[] = $this->entry(0, 'carton_qty', __('orders.imports.columns.carton_qty'), __('orders.imports.warnings.one_carton_per_row'));
        }

        $rows = [];
        $errors = [];
        $rawRows = [];
        $carry = [];
        $seen = [];

        foreach (array_slice($matrix, $headerIndex + 1, self::MAX_ROWS, true) as $matrixIndex => $cells) {
            if ($this->isEmpty($cells) || $this->looksLikeHeader($cells, $columns)) {
                continue;
            }

            $rowNumber = $matrixIndex + 1;
            $raw = [];
            foreach ($cells as $index => $value) {
                $name = trim((string) ($headers[$index] ?? '')) ?: $this->columnName($index);
                $raw[$name] = $value;
            }
            $rawRows[] = ['row' => $rowNumber, 'raw_json' => $raw];

            $signature = md5(implode("\x1F", array_map(fn ($value) => (string) $value, $cells)));
            if (isset($seen[$signature])) {
                $warnings[] = $this->entry($rowNumber, 'consignment_mark', $label('consignment_mark'), __('orders.imports.warnings.duplicate_row', ['row' => $rowNumber, 'first' => $seen[$signature]]));
            } else {
                $seen[$signature] = $rowNumber;
            }

            $mapped = [];
            foreach ($columns as $field => $index) {
                $mapped[$field] = $this->clean($cells[$index] ?? null);
            }
            $this->carryDown($mapped, $carry);

            $converted = $this->convertRow($rowNumber, $mapped, $raw, $headers, $columns, $label, $unitWeightUsed);
            array_push($warnings, ...$converted['warnings']);
            if ($converted['errors'] !== []) {
                array_push($errors, ...$converted['errors']);

                continue;
            }
            $rows[] = $converted['row'];
        }

        if ($unitWeightUsed && $unitWeightHeader !== null) {
            array_unshift($warnings, $this->entry(0, 'actual_weight_kg', $unitWeightHeader, __('orders.imports.warnings.unit_weight', ['field' => $unitWeightHeader])));
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => array_merge($warnings, $this->consistencyWarnings($rows)),
            'raw_rows' => $rawRows,
        ];
    }

    /**
     * One row of the client's sheet → one goods line with every field normalised, or the Chinese errors that keep it out.
     *
     * @param  array<string, ?string>  $mapped
     * @param  array<string, mixed>  $raw
     * @param  list<string|null>  $headers
     * @param  array<string, int>  $columns
     * @param  callable(string): string  $label
     * @return array{row:?array<string, mixed>, errors:list<array<string, mixed>>, warnings:list<array<string, mixed>>}
     */
    private function convertRow(int $rowNumber, array $mapped, array $raw, array $headers, array $columns, callable $label, bool &$unitWeightUsed): array
    {
        $errors = [];
        $warnings = [];
        // CHANGE_REQUESTS #136: the refused row's mark (after carry-down) rides on the entry so the caller can block that whole 唛头.
        $error = function (string $field, string $key, array $replace = []) use (&$errors, $rowNumber, $label, $raw, $mapped): void {
            $errors[] = $this->entry($rowNumber, $field, $label($field), __('orders.imports.errors.'.$key, ['row' => $rowNumber, 'field' => $label($field)] + $replace), $raw)
                + ['consignment_mark' => filled($mapped['consignment_mark'] ?? null) ? (string) $mapped['consignment_mark'] : null];
        };
        $warn = function (string $field, string $key, array $replace = []) use (&$warnings, $rowNumber, $label): void {
            $warnings[] = $this->entry($rowNumber, $field, $label($field), __('orders.imports.warnings.'.$key, ['row' => $rowNumber, 'field' => $label($field)] + $replace));
        };

        // Consignee: state and postcode may sit inside the address ("…, Kemps Creek NSW 2178"); an explicit column wins.
        [$addressState, $addressPostcode, $addressSuburb] = $this->addressParts($mapped['deliver_to_address'] ?? null, $mapped['deliver_to_state'] ?? null, $mapped['deliver_to_postcode'] ?? null);
        $stateText = $addressState;
        $postcode = null;
        $postcodeValid = false;
        if ($addressPostcode !== null) {
            ['value' => $postcode, 'valid' => $postcodeValid, 'padded_from' => $paddedFrom] = $this->postcode($addressPostcode);
            if (! $postcodeValid) {
                $error('deliver_to_postcode', 'invalid_postcode', ['value' => $addressPostcode]);
            } elseif ($paddedFrom !== null) {
                $warn('deliver_to_postcode', 'postcode_padded', ['from' => $paddedFrom, 'to' => $postcode]);
            }
        }
        $state = $this->stateCode($stateText);
        if ($stateText !== null && $state === null) {
            $error('deliver_to_state', 'invalid_state', ['value' => $stateText]);
        } elseif ($stateText === null && $postcodeValid && ($derived = $this->stateForPostcode((string) $postcode)) !== null) {
            $state = $derived;
            $warn('deliver_to_state', 'state_derived', ['postcode' => $postcode, 'state' => $state]);
        } elseif ($state !== null && $postcodeValid && ($expected = $this->stateForPostcode((string) $postcode)) !== null && $expected !== $state) {
            $warn('deliver_to_state', 'state_mismatch', ['state' => $state, 'postcode' => $postcode, 'expected' => $expected]);
        }
        $suburb = $mapped['deliver_to_suburb'] ?? $addressSuburb;

        foreach (['consignment_mark', 'deliver_to_name', 'deliver_to_address'] as $field) {
            if (! filled($mapped[$field] ?? null)) {
                $error($field, 'required');
            }
        }
        if ($state === null && $stateText === null) {
            $error('deliver_to_state', 'required');
        }
        if ($addressPostcode === null) {
            $error('deliver_to_postcode', 'required');
        }

        ['value' => $phone, 'error' => $phoneError, 'padded_from' => $phonePadded] = $this->phone($mapped['deliver_to_phone'] ?? null);
        if ($phoneError !== null) {
            $error('deliver_to_phone', $phoneError, ['value' => (string) $mapped['deliver_to_phone']]);
        } elseif ($phonePadded !== null) {
            $warn('deliver_to_phone', 'phone_padded', ['from' => $phonePadded, 'to' => $phone]);
        }

        // Cartons: an integer count, unit words allowed ("10箱"), decimals refused. CHANGE_REQUESTS #143: a sheet without any 箱数-type
        // column (the consolidation list, one line per carton) reads every row as ONE carton — flagged once per file by normalise().
        $cartons = null;
        if (! isset($columns['carton_qty'])) {
            $cartons = 1;
        } else {
            $cartonValue = $this->number($mapped['carton_qty'] ?? null);
            if ($cartonValue === null || $cartonValue <= 0) {
                $error('carton_qty', 'positive_number', ['value' => (string) ($mapped['carton_qty'] ?? '')]);
            } elseif (abs($cartonValue - round($cartonValue)) > 0.000001) {
                $error('carton_qty', 'integer', ['value' => (string) $mapped['carton_qty']]);
            } else {
                $cartons = (int) round($cartonValue);
            }
        }

        // Weight: the line total; a 单件重量 column is multiplied by 箱数 (flagged once per file).
        $weight = $this->number($mapped['actual_weight_kg'] ?? null);
        $weightField = 'actual_weight_kg';
        if ($weight === null && isset($columns['unit_weight_kg']) && ($unit = $this->number($mapped['unit_weight_kg'] ?? null)) !== null && $cartons !== null) {
            $weight = round($unit * $cartons, 3);
            $unitWeightUsed = true;
        }
        if (! isset($columns['actual_weight_kg']) && isset($columns['unit_weight_kg'])) {
            $weightField = 'unit_weight_kg';
        }
        if ($weight === null || $weight <= 0) {
            $error($weightField, 'positive_number', ['value' => (string) ($mapped[$weightField] ?? '')]);
        }

        $requestedDate = null;
        if (filled($mapped['requested_date'] ?? null)) {
            $requestedDate = $this->date((string) $mapped['requested_date']);
            if ($requestedDate === null) {
                $error('requested_date', 'invalid_date', ['value' => (string) $mapped['requested_date']]);
            }
        }
        $serviceLevel = null;
        if (filled($mapped['service_level'] ?? null)) {
            $serviceLevel = self::SERVICE_LEVELS[$this->normaliseHeader($mapped['service_level'])] ?? null;
            if ($serviceLevel === null) {
                $error('service_level', 'invalid_service_level', ['value' => (string) $mapped['service_level']]);
            }
        }
        // CHANGE_REQUESTS #126 存储等级: null when the sheet has no such column; an empty cell is 标准 but not a declaration.
        $storageTier = isset($columns['storage_tier']) ? 'standard' : null;
        $tierDeclared = false;
        if (filled($mapped['storage_tier'] ?? null)) {
            $storageTier = self::STORAGE_TIER_VALUES[$this->normaliseHeader($mapped['storage_tier'])] ?? null;
            if ($storageTier === null) {
                $error('storage_tier', 'invalid_storage_tier', ['value' => (string) $mapped['storage_tier']]);
            }
            $tierDeclared = $storageTier !== null;
        }
        // CHANGE_REQUESTS #136 地址类型: null without a column or for an empty cell (the caller keeps its default); a recognised word → the enum.
        $addressType = null;
        if (filled($mapped['deliver_to_address_type'] ?? null)) {
            $addressType = self::ADDRESS_TYPE_VALUES[$this->normaliseHeader($mapped['deliver_to_address_type'])] ?? null;
            if ($addressType === null) {
                $error('deliver_to_address_type', 'invalid_address_type', ['value' => (string) $mapped['deliver_to_address_type']]);
            }
        }

        if ($errors !== []) {
            return ['row' => null, 'errors' => $errors, 'warnings' => $warnings];
        }

        $lengthMm = $this->dimension($mapped['length_mm'] ?? null, $headers[$columns['length_mm'] ?? -1] ?? '');
        $widthMm = $this->dimension($mapped['width_mm'] ?? null, $headers[$columns['width_mm'] ?? -1] ?? '');
        $heightMm = $this->dimension($mapped['height_mm'] ?? null, $headers[$columns['height_mm'] ?? -1] ?? '');
        $cbm = $this->number($mapped['cbm'] ?? null);
        if ($cbm === null && $lengthMm && $widthMm && $heightMm) {
            $cbm = round(($lengthMm * $widthMm * $heightMm * $cartons) / 1_000_000_000, 4);
        }
        [$descriptionCn, $descriptionEn] = $this->descriptions($mapped['description_cn'] ?? null, $mapped['description_en'] ?? null, $mapped['description_auto'] ?? null);
        $unitQty = $this->integer($mapped['unit_qty'] ?? null);
        [$unitPriceCents, $totalPriceCents] = $this->prices($this->money($mapped['unit_price_cents'] ?? null), $this->money($mapped['total_price_cents'] ?? null), $this->money($mapped['value_aud'] ?? null), $unitQty);

        return ['row' => [
            'row' => $rowNumber,
            'consignment_mark' => (string) $mapped['consignment_mark'],
            'description_cn' => $descriptionCn,
            'description_en' => $descriptionEn,
            'hs_code' => $mapped['hs_code'] ?? null,
            'material' => $mapped['material'] ?? null,
            'usage' => $mapped['usage'] ?? null,
            'brand' => $mapped['brand'] ?? null,
            'package_type' => $this->packageType($mapped['package_type'] ?? null),
            'carton_qty' => $cartons,
            'unit_qty' => $unitQty,
            'unit_price_cents' => $unitPriceCents,
            'total_price_cents' => $totalPriceCents,
            'actual_weight_kg' => $weight,
            'length_mm' => $lengthMm,
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'cbm' => $cbm,
            'deliver_to_name' => $mapped['deliver_to_name'] ?? null,
            'deliver_to_phone' => $phone,
            'deliver_to_address' => $mapped['deliver_to_address'] ?? null,
            'deliver_to_suburb' => $suburb,
            'deliver_to_state' => $state,
            'deliver_to_postcode' => $postcode,
            'deliver_to_address_type' => $addressType,
            'fba_reference' => $mapped['fba_reference'] ?? null,
            'external_ref' => $mapped['external_ref'] ?? null,
            'requested_date' => $requestedDate,
            'service_level' => $serviceLevel,
            'storage_tier' => $storageTier,
            'storage_tier_declared' => $tierDeclared,
            'raw_json' => $raw,
        ], 'errors' => [], 'warnings' => $warnings];
    }

    /**
     * CHANGE_REQUESTS #143: a "Commodity" / "Description" / "Goods" column (`description_auto`) lands in description_cn when the text
     * has Chinese characters and in description_en otherwise; an explicit 中文品名 / 英文品名 column is never overwritten — the auto
     * text then takes the other slot if that one is empty, else it is dropped.
     *
     * @return array{?string, ?string}
     */
    private function descriptions(?string $cn, ?string $en, ?string $auto): array
    {
        if (! filled($auto)) {
            return [$cn, $en];
        }
        $chinese = preg_match('/\p{Han}/u', (string) $auto) === 1;
        if ($chinese && ! filled($cn)) {
            return [$auto, $en];
        }
        if (! $chinese && ! filled($en)) {
            return [$cn, $auto];
        }
        if (! filled($cn)) {
            return [$auto, $en];
        }
        if (! filled($en)) {
            return [$cn, $auto];
        }

        return [$cn, $en];
    }

    /**
     * CHANGE_REQUESTS #143: the consolidation list's "TTL VALUE(AUD)" (`value_aud`) is the per-unit average next to a 每箱产品总价 carton
     * total — it becomes the unit price only when value × 商品数量 agrees with the total (within 1 AUD or 1 %); standing alone it is the
     * line total; inconsistent → the unit price stays unknown rather than guessed. Explicit 单价 / 总价 columns always win. Prices never
     * price anything (lead answer 4 on CR #126) — they are kept for the order line and the storage-tier pre-fill only.
     *
     * @return array{?int, ?int}
     */
    private function prices(?int $unit, ?int $total, ?int $value, ?int $unitQty): array
    {
        if ($value === null) {
            return [$unit, $total];
        }
        if ($total === null) {
            return [$unit, $value];
        }
        if ($unit === null && $unitQty !== null && $unitQty > 0 && abs($value * $unitQty - $total) <= max(100, (int) round($total * 0.01))) {
            return [$value, $total];
        }

        return [$unit, $total];
    }

    /**
     * Merged cells in the client's sheet: a blank consignee cell under the same 唛头 repeats the previous row's value; a new mark
     * starts afresh. Shared by the sheet reader and the form rows (CHANGE_REQUESTS #128) so both behave identically.
     *
     * @param  array<string, ?string>  $mapped
     * @param  array<string, string>  $carry
     */
    private function carryDown(array &$mapped, array &$carry): void
    {
        if (filled($mapped['consignment_mark'] ?? null)
            && isset($carry['consignment_mark'])
            && mb_strtolower((string) $mapped['consignment_mark']) !== mb_strtolower((string) $carry['consignment_mark'])) {
            $carry = [];
        }
        foreach (self::CARRIED as $field) {
            if (filled($mapped[$field] ?? null)) {
                $carry[$field] = $mapped[$field];
            } elseif (array_key_exists($field, $carry)) {
                $mapped[$field] = $carry[$field];
            }
        }
    }

    /** A posted form value as text: scalars only (an array or object cell is treated as empty). */
    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed>|null $raw */
    private function entry(int $row, string $column, string $label, string $message, ?array $raw = null): array
    {
        $entry = ['row' => $row, 'column' => $column, 'label' => $label, 'message' => $message];
        if ($raw !== null) {
            $entry['raw_json'] = $raw;
        }

        return $entry;
    }

    /**
     * The header row is the one (within the first ten) that maps the most known columns — title rows above it are skipped.
     * A 件数-style column counts cartons only when nothing else does.
     *
     * @param  list<list<string|null>>  $matrix
     * @return array{?int, array<string, int>}
     */
    private function locateHeaders(array $matrix): array
    {
        $bestIndex = null;
        $best = [];
        foreach (array_slice($matrix, 0, 10, true) as $index => $row) {
            $mapped = [];
            $fallback = null;
            foreach ($row as $column => $header) {
                $key = $this->normaliseHeader($header);
                $field = $this->field($key);
                if ($field !== null && ! isset($mapped[$field])) {
                    $mapped[$field] = $column;
                }
                if ($fallback === null && in_array($key, self::CARTON_FALLBACKS, true)) {
                    $fallback = $column;
                }
            }
            if (! isset($mapped['carton_qty']) && $fallback !== null) {
                $mapped['carton_qty'] = $fallback;
                if (($mapped['unit_qty'] ?? null) === $fallback) {
                    unset($mapped['unit_qty']);
                }
            }
            if (count($mapped) >= count($best)) {
                $bestIndex = $index;
                $best = $mapped;
            }
        }

        return [$bestIndex, $best];
    }

    private function field(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        return self::HEADERS[$key] ?? self::HEADERS[preg_replace('/(ctns?|pcs?|kgs?|cm|mm)$/u', '', $key) ?? $key] ?? null;
    }

    /**
     * A repeated header row inside the data (the client pasted two sheets together): three cells that read as header words UNDER the
     * columns mapping to those very fields (one of them structural), or — for a second sheet in another column order — five distinct
     * header words. A data row whose cells happen to read "Receiver" / "carton" / "Goods" never qualifies (CHANGE_REQUESTS #143: the
     * broader alias table made the old "any three known words" rule swallow such a row).
     *
     * @param  list<string|null>  $cells
     * @param  array<string, int>  $columns  the detected header's field → column index
     */
    private function looksLikeHeader(array $cells, array $columns): bool
    {
        $structural = ['consignment_mark', 'carton_qty', 'deliver_to_name', 'deliver_to_address'];
        $positional = collect($columns)->filter(fn (int $index, string $field) => $this->field($this->normaliseHeader($cells[$index] ?? null)) === $field)->keys();
        if ($positional->count() >= 3 && $positional->intersect($structural)->isNotEmpty()) {
            return true;
        }
        $fields = collect($cells)->map(fn ($value) => $this->field($this->normaliseHeader($value)))->filter()->unique();

        return $fields->count() >= 5 && $fields->intersect($structural)->isNotEmpty();
    }

    /** @param list<array<string, mixed>> $rows */
    private function consistencyWarnings(array $rows): array
    {
        $warnings = [];
        foreach (collect($rows)->groupBy('consignment_mark') as $mark => $group) {
            $signatures = $group->map(fn ($row) => $this->deliverySignature($row))->unique();
            if ($signatures->count() > 1) {
                $warnings[] = [
                    'row' => (int) $group->first()['row'],
                    'column' => 'consignment_mark',
                    'label' => __('orders.imports.columns.consignment_mark'),
                    'message' => __('orders.imports.errors.inconsistent_group', ['mark' => $mark]),
                ];
            }
        }

        return $warnings;
    }

    /** @return array{?string, ?string, ?string} */
    private function addressParts(?string $address, ?string $state, ?string $postcode): array
    {
        $address = $this->clean($address);
        $state = $this->clean($state);
        $postcode = $this->clean($postcode);
        $prefix = $address;

        if ($address !== null && preg_match('/\b(NSW|VIC|QLD|SA|WA|TAS|NT|ACT)\s*,?\s*(\d{4})\b/i', $address, $match, PREG_OFFSET_CAPTURE)) {
            $state ??= mb_strtoupper($match[1][0]);
            $postcode ??= $match[2][0];
            $prefix = trim(substr($address, 0, $match[0][1]), " ,\t\n\r\0\x0B");
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $prefix))));
        $suburb = $parts === [] ? null : end($parts);

        return [$state, $postcode, $suburb ?: null];
    }

    /** VIC / Victoria / victoria / 维多利亚 / 维州 → VIC; null when the spelling is unknown. */
    private function stateCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = mb_strtolower(preg_replace('/[\s.\-_,()（）]+/u', '', mb_convert_kana($value, 'as', 'UTF-8')) ?? '');
        if (in_array(mb_strtoupper($key), Enums::STATES, true)) {
            return mb_strtoupper($key);
        }

        return self::STATE_ALIASES[$key] ?? self::STATE_ALIASES[preg_replace('/(州|省)$/u', '', $key) ?? $key] ?? null;
    }

    /** Australia Post ranges: the state a valid postcode belongs to (null outside every range). */
    private function stateForPostcode(string $postcode): ?string
    {
        $n = (int) $postcode;

        return match (true) {
            $n >= 200 && $n <= 299 => 'ACT',
            $n >= 800 && $n <= 999 => 'NT',
            ($n >= 2600 && $n <= 2618) || ($n >= 2900 && $n <= 2920) => 'ACT',
            ($n >= 1000 && $n <= 2599) || ($n >= 2619 && $n <= 2899) || ($n >= 2921 && $n <= 2999) => 'NSW',
            ($n >= 3000 && $n <= 3999) || ($n >= 8000 && $n <= 8999) => 'VIC',
            ($n >= 4000 && $n <= 4999) || ($n >= 9000 && $n <= 9999) => 'QLD',
            $n >= 5000 && $n <= 5999 => 'SA',
            $n >= 6000 && $n <= 6999 => 'WA',
            $n >= 7000 && $n <= 7999 => 'TAS',
            default => null,
        };
    }

    /**
     * Four digits inside an Australian range; a 3-digit value lost its leading zero in Excel (800 → 0800) and is padded; "2178.0"
     * is a numeric cell. Anything else is invalid.
     *
     * @return array{value:?string, valid:bool, padded_from:?string}
     */
    private function postcode(?string $value): array
    {
        $text = trim((string) $value);
        if (! preg_match('/^(\d{3,4})(?:\.0+)?$/', $text, $match)) {
            return ['value' => $text === '' ? null : $text, 'valid' => false, 'padded_from' => null];
        }
        $digits = $match[1];
        $padded = strlen($digits) === 3 ? '0'.$digits : $digits;
        if ($this->stateForPostcode($padded) === null) {
            return ['value' => $padded, 'valid' => false, 'padded_from' => null];
        }

        return ['value' => $padded, 'valid' => true, 'padded_from' => strlen($digits) === 3 ? $digits : null];
    }

    /**
     * Phones stay text. Excel's scientific notation (4.12E+8) or a float export (412345678.0) has lost digits → error naming the fix.
     * Otherwise spaces / dashes / brackets are removed, +61 / 61 becomes the domestic 0, and a 9-digit number that lost its leading 0
     * is padded (warning). Free text with letters is kept as typed.
     *
     * @return array{value:?string, error:?string, padded_from:?string}
     */
    private function phone(?string $value): array
    {
        $text = trim((string) $value);
        if ($text === '') {
            return ['value' => null, 'error' => null, 'padded_from' => null];
        }
        if (preg_match('/^\d+(?:\.\d+)?e[+-]?\d+$/i', $text) || preg_match('/^\d{6,}\.\d+$/', $text)) {
            return ['value' => $text, 'error' => 'phone_scientific', 'padded_from' => null];
        }
        if (! preg_match('/^\+?[\d\s\-().]+$/', $text)) {
            return ['value' => $text, 'error' => null, 'padded_from' => null];
        }

        $digits = preg_replace('/\D+/', '', $text) ?? '';
        $international = str_starts_with($text, '+');
        if (strlen($digits) === 11 && str_starts_with($digits, '61')) {
            return ['value' => '0'.substr($digits, 2), 'error' => null, 'padded_from' => null];
        }
        if ($international) {
            return ['value' => '+'.$digits, 'error' => null, 'padded_from' => null];
        }
        if (strlen($digits) === 9 && preg_match('/^[2-9]/', $digits)) {
            return ['value' => '0'.$digits, 'error' => null, 'padded_from' => $digits];
        }

        return ['value' => $digits, 'error' => null, 'padded_from' => null];
    }

    /** ISO, slash, dot, 年月日 and Australian d/m/Y spellings, plus Excel's serial numbers → Y-m-d; null when unreadable. */
    private function date(string $value): ?string
    {
        $text = trim($value);
        if (preg_match('/^\d{5}(?:\.0+)?$/', $text)) { // Excel serial (1899-12-30 epoch), 2027-2100 range
            $serial = (int) $text;
            if ($serial >= 40000 && $serial <= 80000) {
                return (new DateTimeImmutable('1899-12-30'))->modify("+{$serial} days")->format('Y-m-d');
            }

            return null;
        }
        $text = preg_replace('/[\sT]\d{1,2}:\d{2}(?::\d{2})?.*$/', '', $text) ?? $text;
        $candidates = [
            '/^(\d{4})[-\/.年](\d{1,2})[-\/.月](\d{1,2})日?$/u' => fn ($m) => [$m[1], $m[2], $m[3]],
            '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/' => fn ($m) => [$m[3], $m[2], $m[1]],
        ];
        foreach ($candidates as $pattern => $order) {
            if (preg_match($pattern, $text, $match)) {
                [$year, $month, $day] = array_map('intval', $order($match));
                if (checkdate($month, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }

                return null;
            }
        }

        return null;
    }

    private function packageType(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'carton';
        }
        $key = mb_strtolower(preg_replace('/[\s_\-\/]+/u', '', mb_convert_kana($value, 'as', 'UTF-8')) ?? '');

        return self::PACKAGE_TYPES[$key] ?? $value;
    }

    private function deliverySignature(array $row): string
    {
        return mb_strtolower(implode('|', array_map(fn ($value) => trim((string) $value), [
            $row['deliver_to_name'] ?? null,
            $row['deliver_to_address'] ?? null,
            $row['deliver_to_state'] ?? null,
            $row['deliver_to_postcode'] ?? null,
            $row['deliver_to_address_type'] ?? null, // CHANGE_REQUESTS #136: 住宅 on one row and 商业 on another of the same mark is an inconsistency too
            $row['fba_reference'] ?? null,
        ])));
    }

    /** Lower case, half-width, without spaces / brackets / punctuation / the "*" required marker: "英文品名*" → 英文品名, "Weight (kg)" → weightkg. */
    private function normaliseHeader(?string $header): string
    {
        $header = mb_strtolower(mb_convert_kana(trim((string) $header), 'as', 'UTF-8'));

        return preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}_\-\/\\\\（）()\[\]【】《》{}*.:：;；,，、"\'“”‘’#?？!！|+&%°º№]+/u', '', $header) ?? $header;
    }

    /** Half-width digits / letters / punctuation, NBSP → space, zero-width characters removed, trimmed; empty → null. */
    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF"], [' ', '', '', '', ''], $value);
        $value = mb_convert_kana($value, 'as', 'UTF-8');
        $value = trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }

    /** Numeric value after thousands separators, spaces and trailing unit words (kg, cm, 箱, ctn, pcs…) are removed; null otherwise. */
    private function number(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $text = mb_strtolower(trim($value));
        $text = preg_replace('/\s*(kgs?|公斤|千克|cbm|m³|m3|cm|mm|箱|件|个|只|pcs?|ctns?|cartons?|boxes|box)\s*$/u', '', $text) ?? $text;
        $text = str_replace([',', ' '], '', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    private function integer(?string $value): ?int
    {
        $number = $this->number($value);

        return $number === null ? null : (int) round($number);
    }

    private function money(?string $value): ?int
    {
        $decimal = $this->number($value);

        return $decimal === null ? null : (int) round($decimal * 100);
    }

    /** Millimetres; a header that names cm (长(CM), Length (cm)) is converted ×10. */
    private function dimension(?string $value, ?string $header): ?int
    {
        $decimal = $this->number($value);
        if ($decimal === null) {
            return null;
        }

        return (int) round($decimal * (str_contains($this->normaliseHeader($header), 'cm') ? 10 : 1));
    }

    /** @param list<string|null> $row */
    private function isEmpty(array $row): bool
    {
        return collect($row)->every(fn ($value) => ! filled($value));
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + ord($letter) - 64;
        }

        return $index - 1;
    }

    private function columnName(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr((($number - 1) % 26) + 65).$name;
        }

        return $name;
    }
}
