-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 23, 2025 at 07:01 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tnvs`
--

-- --------------------------------------------------------

--
-- Table structure for table `inventory_categories`
--

CREATE TABLE `inventory_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_categories`
--

INSERT INTO `inventory_categories` (`id`, `code`, `name`, `description`, `active`, `created_at`) VALUES
(1, 'RAW', 'Raw', 'Raw materials', 1, '2025-08-17 11:23:32'),
(2, 'PACK', 'Packaging', 'Packaging supplies', 1, '2025-08-17 11:23:32'),
(3, 'FIN', 'Finished', 'Finished goods', 1, '2025-08-17 11:23:32');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_items`
--

CREATE TABLE `inventory_items` (
  `id` int(11) NOT NULL,
  `sku` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` enum('Raw','Packaging','Finished') NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `reorder_level` int(11) NOT NULL DEFAULT 0,
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `location` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_items`
--

INSERT INTO `inventory_items` (`id`, `sku`, `name`, `category`, `stock`, `reorder_level`, `archived`, `archived_at`, `location`, `created_at`, `is_active`) VALUES
(5, 'SKU001', 'Plastic Crates', 'Raw', 12, 0, 0, NULL, 'Multiple', '2025-08-13 09:29:14', 1),
(7, 'SKU003', 'Rubber Shoes', 'Packaging', 10, 1, 0, NULL, 'Main Warehouse', '2025-08-13 12:04:40', 1),
(13, 'SKU002', 'Sandals', 'Packaging', 5, 0, 0, NULL, 'WH5', '2025-08-14 20:23:32', 1);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `status` enum('draft','approved','ordered','partially_received','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `po_no` varchar(32) NOT NULL,
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `pr_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `supplier_id`, `order_date`, `expected_date`, `status`, `notes`, `created_by`, `created_at`, `po_no`, `total`, `pr_id`) VALUES
(1, 5, '2025-08-22', '2025-08-22', 'received', '', NULL, '2025-08-22 04:16:05', 'PO-202508-0001', 100.00, NULL),
(2, 5, '2025-08-22', '2025-08-22', 'received', 'From RFQ #1', NULL, '2025-08-22 04:16:18', 'PO-202508-0002', 10.00, NULL),
(3, 5, '2025-08-23', '2025-08-24', 'received', 'From RFQ #2', NULL, '2025-08-23 16:58:42', 'PO-202508-0003', 12.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `descr` varchar(255) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `qty_ordered` int(11) DEFAULT NULL,
  `qty_received` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(12,2) DEFAULT 0.00,
  `note` varchar(255) DEFAULT NULL,
  `qty` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `price` decimal(14,4) NOT NULL DEFAULT 0.0000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`id`, `po_id`, `descr`, `item_id`, `location_id`, `qty_ordered`, `qty_received`, `unit_cost`, `note`, `qty`, `price`) VALUES
(1, 1, 'item', NULL, NULL, NULL, 10, 0.00, NULL, 10.0000, 10.0000),
(2, 2, 'Awarded total', NULL, NULL, NULL, 1, 0.00, NULL, 1.0000, 10.0000),
(4, 3, 'Awarded total', NULL, NULL, NULL, 0, 0.00, NULL, 1.0000, 12.0000);

-- --------------------------------------------------------

--
-- Table structure for table `quotes`
--

CREATE TABLE `quotes` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `lead_time_days` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `total_cache` decimal(18,2) DEFAULT NULL,
  `is_final` tinyint(1) NOT NULL DEFAULT 1,
  `total_amount` decimal(18,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quotes`
--

INSERT INTO `quotes` (`id`, `rfq_id`, `supplier_id`, `submitted_at`, `lead_time_days`, `notes`, `total_cache`, `is_final`, `total_amount`) VALUES
(1, 1, 5, '2025-08-22 06:15:38', 1, '', NULL, 1, 10.00),
(2, 2, 5, '2025-08-23 18:58:35', 1, '', NULL, 1, 12.00);

-- --------------------------------------------------------

--
-- Table structure for table `quote_attachments`
--

CREATE TABLE `quote_attachments` (
  `id` int(11) NOT NULL,
  `quote_id` int(11) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `path` varchar(512) NOT NULL,
  `mime` varchar(128) NOT NULL,
  `size_bytes` int(11) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quote_items`
--

CREATE TABLE `quote_items` (
  `id` int(11) NOT NULL,
  `quote_id` int(11) NOT NULL,
  `rfq_item_id` int(11) NOT NULL,
  `unit_price` decimal(18,4) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'PHP',
  `quantity` decimal(12,3) NOT NULL DEFAULT 1.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rfqs`
--

CREATE TABLE `rfqs` (
  `id` int(11) NOT NULL,
  `rfq_no` varchar(20) NOT NULL,
  `title` varchar(160) NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('draft','sent','awarded','closed','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `awarded_supplier_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rfqs`
--

INSERT INTO `rfqs` (`id`, `rfq_no`, `title`, `due_date`, `status`, `notes`, `created_at`, `sent_at`, `awarded_supplier_id`) VALUES
(1, 'RFQ-20250822-0002', 'Nicole Sample', '2025-08-22', 'awarded', '', '2025-08-22 04:15:30', NULL, 5),
(2, 'RFQ-20250823-0001', 'sample 20424', '2025-08-24', 'awarded', '', '2025-08-23 16:58:24', NULL, 5);

-- --------------------------------------------------------

--
-- Table structure for table `rfq_counters`
--

CREATE TABLE `rfq_counters` (
  `day` char(8) NOT NULL,
  `seq` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rfq_counters`
--

INSERT INTO `rfq_counters` (`day`, `seq`) VALUES
('20250818', 161959),
('20250819', 4),
('20250822', 2),
('20250823', 1);

-- --------------------------------------------------------

--
-- Table structure for table `rfq_items`
--

CREATE TABLE `rfq_items` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT 1.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rfq_quotes`
--

CREATE TABLE `rfq_quotes` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `total` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rfq_recipients`
--

CREATE TABLE `rfq_recipients` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `invite_token` char(64) NOT NULL DEFAULT '',
  `token_expires_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rfq_recipients`
--

INSERT INTO `rfq_recipients` (`id`, `rfq_id`, `supplier_id`, `email`, `invite_token`, `token_expires_at`, `sent_at`, `opened_at`) VALUES
(1, 1, 5, 'nicole@viahale.com', 'b7c86c71ef1bb69c2e7478553349d00216c04628d2b6982713972ffa4ec8cbaa', '2025-08-29 06:15:32', '2025-08-22 12:15:32', NULL),
(2, 2, 5, 'nicole@viahale.com', 'a840f1b4239925bac6d523ced8f0298f1bbf83d9eb983e417e69356150d96c10', '2025-08-30 18:58:26', '2025-08-24 00:58:26', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `rfq_suppliers`
--

CREATE TABLE `rfq_suppliers` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `status` enum('invited','quoted','declined') NOT NULL DEFAULT 'invited',
  `quote_total` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `id` int(11) NOT NULL,
  `ref_no` varchar(50) NOT NULL,
  `origin_id` int(11) NOT NULL,
  `destination_id` int(11) NOT NULL,
  `status` enum('Draft','Ready','Dispatched','In Transit','Delivered','Delayed','Cancelled','Returned') NOT NULL DEFAULT 'Draft',
  `carrier` varchar(120) DEFAULT NULL,
  `contact_name` varchar(120) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `expected_pickup` date DEFAULT NULL,
  `expected_delivery` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shipments`
--

INSERT INTO `shipments` (`id`, `ref_no`, `origin_id`, `destination_id`, `status`, `carrier`, `contact_name`, `contact_phone`, `expected_pickup`, `expected_delivery`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'SHP-20250814-4802', 1, 2, 'Delivered', 'LBC', 'Nicole Malitao', '09169271961', NULL, '2025-08-14', '', 1, '2025-08-14 11:29:26', '2025-08-14 11:36:28'),
(3, 'SHP-20250814-9243', 1, 2, 'Delivered', '', 'Nicole Malitao', '09169271961', '2025-08-15', '2025-08-15', '', 1, '2025-08-14 12:28:41', '2025-08-14 12:39:52'),
(4, 'SHP-20250814-2768', 1, 2, 'Delivered', 'LBC', 'Nicole Malitao', '09169271961', '2025-08-14', '2025-08-14', '', 1, '2025-08-14 13:52:10', '2025-08-14 16:32:23'),
(5, 'SHP-20250814-8145', 7, 1, 'Delivered', 'J&T', 'Nicole Malitao', '09169271961', '2025-08-16', '2025-08-16', '', 1, '2025-08-14 20:25:31', '2025-08-14 20:27:05');

-- --------------------------------------------------------

--
-- Table structure for table `shipment_events`
--

CREATE TABLE `shipment_events` (
  `id` int(11) NOT NULL,
  `shipment_id` int(11) NOT NULL,
  `event_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `event_type` varchar(50) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shipment_events`
--

INSERT INTO `shipment_events` (`id`, `shipment_id`, `event_time`, `event_type`, `details`, `user_id`) VALUES
(2, 1, '2025-08-14 11:34:18', 'Dispatched', 'Arrived at Qc hub', 1),
(4, 1, '2025-08-14 11:36:28', 'Delivered', NULL, 1),
(6, 3, '2025-08-14 12:28:41', 'Draft', 'Shipment created', 1),
(7, 3, '2025-08-14 12:30:23', 'Dispatched', NULL, 1),
(8, 3, '2025-08-14 12:30:27', 'In Transit', NULL, 1),
(9, 3, '2025-08-14 12:30:32', 'Delivered', NULL, 1),
(10, 3, '2025-08-14 12:31:05', 'Returned', NULL, 1),
(11, 3, '2025-08-14 12:31:12', 'Delayed', NULL, 1),
(12, 3, '2025-08-14 12:31:22', 'Delivered', NULL, 1),
(13, 3, '2025-08-14 12:32:16', 'Returned', NULL, 1),
(14, 3, '2025-08-14 12:39:45', 'In Transit', NULL, 1),
(15, 3, '2025-08-14 12:39:52', 'Delivered', NULL, 1),
(16, 4, '2025-08-14 13:52:10', 'Draft', 'Shipment created', 1),
(17, 4, '2025-08-14 13:52:26', 'Dispatched', NULL, 1),
(18, 4, '2025-08-14 13:52:29', 'In Transit', NULL, 1),
(21, 4, '2025-08-14 16:32:23', 'Delivered', 'Arrived at WH2', 1),
(22, 5, '2025-08-14 20:25:31', 'Draft', 'Shipment created', 1),
(23, 5, '2025-08-14 20:25:50', 'Dispatched', 'Your order has been shipped', 1),
(24, 5, '2025-08-14 20:26:10', 'In Transit', NULL, 1),
(25, 5, '2025-08-14 20:27:05', 'Delivered', 'Your order has been delivered', 1);

-- --------------------------------------------------------

--
-- Table structure for table `shipment_items`
--

CREATE TABLE `shipment_items` (
  `id` int(11) NOT NULL,
  `shipment_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(18,2) NOT NULL,
  `uom` varchar(20) DEFAULT 'pcs'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_levels`
--

CREATE TABLE `stock_levels` (
  `item_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_levels`
--

INSERT INTO `stock_levels` (`item_id`, `location_id`, `qty`) VALUES
(5, 1, 10),
(5, 2, 0),
(5, 3, 2),
(7, 1, 10),
(7, 2, 0),
(7, 3, 0),
(13, 1, 5),
(13, 7, 0);

-- --------------------------------------------------------

--
-- Table structure for table `stock_transactions`
--

CREATE TABLE `stock_transactions` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `from_location_id` int(11) DEFAULT NULL,
  `to_location_id` int(11) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `action` enum('IN','OUT','TRANSFER','ADJUST') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_transactions`
--

INSERT INTO `stock_transactions` (`id`, `item_id`, `from_location_id`, `to_location_id`, `qty`, `action`, `note`, `user_id`, `created_at`) VALUES
(1, 5, NULL, 1, 5, 'IN', '', 1, '2025-08-13 11:24:20'),
(2, 5, 1, NULL, 2, 'OUT', '', 1, '2025-08-13 11:30:06'),
(3, 5, 1, 2, 1, 'TRANSFER', '', 1, '2025-08-13 11:30:49'),
(4, 5, NULL, 1, 10, 'IN', '', 1, '2025-08-13 11:39:55'),
(5, 5, NULL, 1, 5, 'IN', '', 1, '2025-08-13 11:40:11'),
(6, 5, NULL, 2, 5, 'IN', '', 1, '2025-08-13 11:40:33'),
(7, 5, NULL, 2, 5, 'IN', '', 1, '2025-08-13 11:40:56'),
(8, 5, 1, NULL, 5, 'OUT', '', 1, '2025-08-13 11:41:11'),
(9, 5, 1, NULL, 10, 'OUT', '', 1, '2025-08-13 11:42:15'),
(10, 5, 2, NULL, 2, 'OUT', '', 1, '2025-08-13 11:45:44'),
(11, 5, NULL, 1, 3, 'IN', '', 1, '2025-08-13 11:52:29'),
(12, 5, 1, NULL, 5, 'OUT', '', 1, '2025-08-13 11:52:50'),
(13, 5, NULL, 2, 5, 'IN', '', 1, '2025-08-13 11:53:00'),
(16, 7, NULL, 2, 5, 'IN', '', 1, '2025-08-13 12:04:57'),
(19, 5, NULL, 1, 10, 'IN', '', 1, '2025-08-13 14:18:08'),
(24, 5, 2, NULL, 5, 'OUT', '', 1, '2025-08-13 16:25:45'),
(27, 7, 2, 1, 1, 'TRANSFER', '', 1, '2025-08-13 16:30:48'),
(28, 5, 1, NULL, 10, 'OUT', '', 1, '2025-08-13 16:39:53'),
(29, 5, 2, 1, 5, 'TRANSFER', '', 1, '2025-08-13 16:40:16'),
(30, 5, 1, NULL, 5, 'OUT', '', 1, '2025-08-13 16:42:55'),
(31, 7, 1, 2, 1, 'TRANSFER', '', 1, '2025-08-13 16:44:29'),
(32, 5, 2, 1, 1, 'TRANSFER', '', 1, '2025-08-13 16:45:11'),
(33, 5, 2, 1, 1, 'TRANSFER', '', 1, '2025-08-13 16:46:36'),
(34, 7, 2, 1, 5, 'TRANSFER', '', 1, '2025-08-13 16:48:16'),
(35, 5, 2, 1, 2, 'TRANSFER', '', 1, '2025-08-13 16:49:04'),
(36, 5, 1, 2, 4, 'TRANSFER', '', 1, '2025-08-13 17:01:42'),
(37, 5, 2, 1, 2, 'TRANSFER', '', 1, '2025-08-13 17:01:56'),
(38, 5, 2, 1, 2, 'TRANSFER', '', 1, '2025-08-13 17:02:13'),
(42, 7, 1, 3, 5, 'TRANSFER', '', 1, '2025-08-13 17:53:33'),
(43, 7, NULL, 3, 5, 'IN', '', 1, '2025-08-14 11:57:51'),
(44, 5, NULL, 1, 6, 'IN', '', 1, '2025-08-14 11:58:07'),
(45, 7, 3, 1, 2, 'TRANSFER', '', 1, '2025-08-14 16:21:49'),
(46, 7, 3, 1, 8, 'TRANSFER', '', 1, '2025-08-14 16:22:06'),
(47, 5, NULL, 3, 2, 'IN', '', 1, '2025-08-14 18:53:36'),
(48, 13, NULL, 7, 10, 'IN', '', 1, '2025-08-14 20:23:48'),
(49, 13, 7, NULL, 5, 'OUT', '', 1, '2025-08-14 20:24:21'),
(50, 13, 7, 1, 5, 'TRANSFER', 'gusto ko lang. bawal ba?', 1, '2025-08-14 20:24:43');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(128) NOT NULL,
  `contact_person` varchar(128) DEFAULT NULL,
  `email` varchar(128) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `rating` tinyint(4) DEFAULT NULL,
  `lead_time_days` int(11) NOT NULL DEFAULT 0,
  `payment_terms` varchar(60) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `code`, `name`, `contact_person`, `email`, `phone`, `address`, `rating`, `lead_time_days`, `payment_terms`, `notes`, `is_active`, `created_at`) VALUES
(1, 'SUP-00001', 'Acme Packaging', 'John Doe', 'sales@acme.com', '+63 912 345 6789', 'QC, PH', 4, 7, 'Net 30', 'Primary packaging', 0, '2025-08-18 10:22:27'),
(2, 'SUP-00002', 'Green Raw Materials', 'Jane Doe', 'contact@greenraw.ph', '+63 922 111 2222', 'Makati, PH', 5, 5, 'Net 15', 'Eco-friendly raw mats', 0, '2025-08-18 10:22:27'),
(3, 'SUP-00003', 'Ana Marie Lip Tint', '914545487256', 'anamariee@viahale.com', '8454576', 'Manila, PH', 4, 0, 'Gcash', '', 0, '2025-08-18 11:04:46'),
(4, 'SUP-00006', 'Jaren Adriano', '095454656523', 'sample@viahale.com', '928492849', '242 Manila', 5, 0, 'gcash', '', 0, '2025-08-18 19:17:56'),
(5, '1001', 'Nicole\'s Liptint', 'Nicole Malitao', 'nicole@viahale.com', '9402942', 'Quezon City', 5, 1, 'Card', '', 1, '2025-08-22 04:15:11');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','manager','staff','viewer','procurement') NOT NULL DEFAULT 'staff',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `created_at`) VALUES
(1, 'Administrator', 'admin@tnvs.local', '$2y$10$m0HppT4WAqnl.K7Hy2BwH.tvnTuyJOCgdDafIFaThwMJHR.vjR8XG', 'admin', '2025-08-15 09:54:39'),
(2, 'Nicole', 'manager@viahale.com', '$2y$10$yP4KUyK7PSSCcy5UNc19iOOziIN1xoZ8/XixtP/jS4DkL.DDFRuPe', 'manager', '2025-08-15 10:59:22');

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_locations`
--

CREATE TABLE `warehouse_locations` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `warehouse_locations`
--

INSERT INTO `warehouse_locations` (`id`, `code`, `name`, `address`) VALUES
(1, 'WH1', 'Main Warehouse', NULL),
(2, 'WH2', 'Overflow', NULL),
(3, 'WH3', 'Iskibidi House', NULL),
(7, 'WH5', 'Bahay ni nicole', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `inventory_categories`
--
ALTER TABLE `inventory_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_po_no` (`po_no`),
  ADD UNIQUE KEY `po_no_UNIQUE` (`po_no`),
  ADD KEY `idx_po_supplier_status` (`supplier_id`,`status`),
  ADD KEY `idx_po_supplier` (`supplier_id`),
  ADD KEY `pr_id` (`pr_id`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_poi_item` (`item_id`),
  ADD KEY `fk_poi_loc` (`location_id`),
  ADD KEY `idx_poi_po` (`po_id`);

--
-- Indexes for table `quotes`
--
ALTER TABLE `quotes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rfq_id` (`rfq_id`,`supplier_id`,`is_final`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `quote_attachments`
--
ALTER TABLE `quote_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quote_id` (`quote_id`);

--
-- Indexes for table `quote_items`
--
ALTER TABLE `quote_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `quote_id` (`quote_id`,`rfq_item_id`);

--
-- Indexes for table `rfqs`
--
ALTER TABLE `rfqs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rfq_no` (`rfq_no`);

--
-- Indexes for table `rfq_counters`
--
ALTER TABLE `rfq_counters`
  ADD PRIMARY KEY (`day`);

--
-- Indexes for table `rfq_items`
--
ALTER TABLE `rfq_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rfq_quotes`
--
ALTER TABLE `rfq_quotes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rfq_recipients`
--
ALTER TABLE `rfq_recipients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rfq_id` (`rfq_id`,`supplier_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `rfq_suppliers`
--
ALTER TABLE `rfq_suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rfq_sup_rfq` (`rfq_id`),
  ADD KEY `fk_rfq_sup_supplier` (`supplier_id`);

--
-- Indexes for table `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ref_no` (`ref_no`),
  ADD KEY `idx_ship_status` (`status`),
  ADD KEY `idx_ship_dates` (`expected_delivery`,`expected_pickup`),
  ADD KEY `idx_ship_origin_dest` (`origin_id`,`destination_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_eta` (`expected_delivery`),
  ADD KEY `idx_origin` (`origin_id`),
  ADD KEY `idx_dest` (`destination_id`),
  ADD KEY `idx_ref` (`ref_no`),
  ADD KEY `idx_carrier` (`carrier`);

--
-- Indexes for table `shipment_events`
--
ALTER TABLE `shipment_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_se_ship_time` (`shipment_id`,`event_time`),
  ADD KEY `idx_ev_ship_time` (`shipment_id`,`event_time`);

--
-- Indexes for table `shipment_items`
--
ALTER TABLE `shipment_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_si_ship_item` (`shipment_id`,`item_id`),
  ADD KEY `idx_si_item` (`item_id`);

--
-- Indexes for table `stock_levels`
--
ALTER TABLE `stock_levels`
  ADD PRIMARY KEY (`item_id`,`location_id`),
  ADD KEY `fk_sl_loc` (`location_id`),
  ADD KEY `idx_item_loc` (`item_id`,`location_id`);

--
-- Indexes for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_st_item` (`item_id`),
  ADD KEY `fk_st_from` (`from_location_id`),
  ADD KEY `fk_st_to` (`to_location_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_suppliers_code` (`code`),
  ADD KEY `idx_suppliers_name` (`name`),
  ADD KEY `idx_suppliers_active` (`is_active`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `warehouse_locations`
--
ALTER TABLE `warehouse_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_loc_code` (`code`),
  ADD KEY `idx_loc_name` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `inventory_categories`
--
ALTER TABLE `inventory_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `inventory_items`
--
ALTER TABLE `inventory_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `quotes`
--
ALTER TABLE `quotes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `quote_attachments`
--
ALTER TABLE `quote_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quote_items`
--
ALTER TABLE `quote_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rfqs`
--
ALTER TABLE `rfqs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `rfq_items`
--
ALTER TABLE `rfq_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rfq_quotes`
--
ALTER TABLE `rfq_quotes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rfq_recipients`
--
ALTER TABLE `rfq_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `rfq_suppliers`
--
ALTER TABLE `rfq_suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `shipments`
--
ALTER TABLE `shipments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `shipment_events`
--
ALTER TABLE `shipment_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `shipment_items`
--
ALTER TABLE `shipment_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `warehouse_locations`
--
ALTER TABLE `warehouse_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_supplier_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_poi_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_poi_loc` FOREIGN KEY (`location_id`) REFERENCES `warehouse_locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `quotes`
--
ALTER TABLE `quotes`
  ADD CONSTRAINT `quotes_ibfk_1` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`),
  ADD CONSTRAINT `quotes_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `quote_attachments`
--
ALTER TABLE `quote_attachments`
  ADD CONSTRAINT `quote_attachments_ibfk_1` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`);

--
-- Constraints for table `quote_items`
--
ALTER TABLE `quote_items`
  ADD CONSTRAINT `quote_items_ibfk_1` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`);

--
-- Constraints for table `rfq_recipients`
--
ALTER TABLE `rfq_recipients`
  ADD CONSTRAINT `rfq_recipients_ibfk_1` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`),
  ADD CONSTRAINT `rfq_recipients_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `rfq_suppliers`
--
ALTER TABLE `rfq_suppliers`
  ADD CONSTRAINT `fk_rfq_sup_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rfq_sup_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `shipments`
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `fk_ship_dest` FOREIGN KEY (`destination_id`) REFERENCES `warehouse_locations` (`id`),
  ADD CONSTRAINT `fk_ship_origin` FOREIGN KEY (`origin_id`) REFERENCES `warehouse_locations` (`id`);

--
-- Constraints for table `shipment_events`
--
ALTER TABLE `shipment_events`
  ADD CONSTRAINT `fk_se_ship` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shipment_items`
--
ALTER TABLE `shipment_items`
  ADD CONSTRAINT `fk_si_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`),
  ADD CONSTRAINT `fk_si_ship` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_levels`
--
ALTER TABLE `stock_levels`
  ADD CONSTRAINT `fk_sl_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`),
  ADD CONSTRAINT `fk_sl_loc` FOREIGN KEY (`location_id`) REFERENCES `warehouse_locations` (`id`);

--
-- Constraints for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  ADD CONSTRAINT `fk_st_from` FOREIGN KEY (`from_location_id`) REFERENCES `warehouse_locations` (`id`),
  ADD CONSTRAINT `fk_st_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`),
  ADD CONSTRAINT `fk_st_to` FOREIGN KEY (`to_location_id`) REFERENCES `warehouse_locations` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
