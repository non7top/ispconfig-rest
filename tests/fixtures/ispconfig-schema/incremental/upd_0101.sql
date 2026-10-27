-- the column the code must not write below level 101
ALTER TABLE `client` ADD `late_column` int(11) NOT NULL default '0' AFTER `mid_column`;
