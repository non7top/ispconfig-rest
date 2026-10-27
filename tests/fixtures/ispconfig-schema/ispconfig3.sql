CREATE TABLE `client` (
  `client_id` int(11) unsigned NOT NULL auto_increment,
  `company_name` varchar(64) default NULL,
  `mid_column` int(11) default NULL,
  `late_column` int(11) NOT NULL default '0',
  PRIMARY KEY  (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
