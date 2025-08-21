-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 17, 2025 at 03:11 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

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
