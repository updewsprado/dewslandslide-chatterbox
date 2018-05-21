-- MySQL dump 10.13  Distrib 5.5.59, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: senslopedb
-- ------------------------------------------------------
-- Server version	5.5.59-0ubuntu0.14.04.1-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `dewslcontacts`
--

DROP TABLE IF EXISTS `dewslcontacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dewslcontacts` (
  `eid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lastname` varchar(255) DEFAULT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `numbers` varchar(255) DEFAULT NULL,
  `grouptags` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`eid`)
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dewslcontacts`
--

LOCK TABLES `dewslcontacts` WRITE;
/*!40000 ALTER TABLE `dewslcontacts` DISABLE KEYS */;
INSERT INTO `dewslcontacts` VALUES (1,'Bantay','Kristine','TinB','1993-08-11','kristinebantay@gmail.com','09178028466','senslope,datalogger'),(2,'Bognot','Prado Arturo','Prado','1988-07-27','updews.prado@gmail.com','09980619501','senslope,database,web'),(3,'Bontia','Carlo','Carlo','1991-02-08','angemailnicarlo@gmail.com','09228912093','dynaslope,monitoring,alert'),(4,'Boyles','Nathalie Ross','Nath','1991-07-02','nathalieboyles@yahoo.com','09177975821','senslope,community'),(5,'Bundoc','Paul Eugene','Pol','1990-06-08','pedbundoc@gmail.com','09069267748',''),(6,'Calda','Kristine Joy','TinC','1991-06-15','tintin.calda15@gmail.com','09166972046','senslope,admin'),(7,'Complativo','Claudine','Claud','1994-02-24','cmcomplativo@gmail.com','09065898180','dynaslope,community,alert-mon'),(8,'Cordero','Cathleen Joyce','Cath','1986-09-18','cjncordero@gmail.com','09228959530','dynaslope,survey'),(9,'de Guzman','Jeremiah Christian','Jec','1994-10-17','jeremiah.dg17@gmail.com','09569023671','senslope,maintenance,deployment'),(10,'Decena','Eunice','Eunice','1993-01-05','eunicerdecena@gmail.com','09475224117,09270191706','dynaslope,community'),(11,'Bognot','Prado Arturo','Prado','1988-07-27','updews.prado@gmail.com','09980619501','senslope,database,web,swat'),(12,'Domingo','Mark Arnel','Arnel','1992-09-11','dmarkarnel@gmail.com','09161640761','senslope,maintenance,deployment'),(13,'Flores','Edchelle','Edch','1993-01-13','edchelleflores@gmail.com','09173014635','dynaslope,community'),(14,'Gapac','Gilbert Luis','Gibo','1992-08-14','ggilbertluis@gmail.com','09151368303','senslope,accel-validation,alert'),(15,'Garcia','Daisy Amor','Amor','1993-11-02','daisyamorgarcia29@gmail.com ','09152522509','dynaslope,admin'),(16,'Garcia','Marjorie','Marj','1994-06-13','marjoriengarcia@gmail.com','09159309183','dynaslope,survey,alert-mon'),(17,'Gases','Junril','Junril','1989-11-28','junrilgases@gmail.com','09755448589','senslope,maintenance,deployment'),(18,'Gasmen','Harianne','Harry','1986-11-10','haryan.agham@gmail.com','09285512242','senslope,community'),(19,'Guanzon','Oscar','Oscar','1991-11-03','oscarguanzon0@gmail.com','09773261540','senslope,maintenance,deployment'),(20,'Gumiran','Brian Anthony','Biboy','1990-02-05','brian.gumiran@gmail.com','09266413958','senslope,piezo,validation'),(21,'Jacela','Angelica','Anj','1993-10-22','angelica.jacela@gmail.com','09059591933','dynaslope,survey'),(22,'Kaimo','Roy Albert','Roy','1986-06-04','roy.alkai@gmail.com','09433444666','dynaslope,survey'),(23,'Lorenzo','Leodegario','Leo','1991-06-29','leolorenzoii@gmail.com','09054537225','dynaslope,monitoring,alert,alert-mon'),(24,'Malaluan','Amelia','Amy','1959-03-28','amelia.malaluan@gmail.com','09153011945','senslope,admin'),(25,'Maligon','Ardeth','Ardeth','1989-10-10','ardethmaligon@gmail.com','09985356659','dynaslope,admin'),(26,'Mallari','Glenn Marvin','Glenn','1991-05-25','marvin.mallari88@gmail.com','09997373335','dynaslope,community'),(27,'Mendoza','Earl Anthony','Earl','1986-11-19','earlmendoza@gmail.com','09176023735,09391510307','undefined'),(28,'Mercado','Morgan','Morgan','1991-08-12','morgan.mercado@gmail.com','09475573312','dynaslope,survey'),(29,'Nazal','Micah Angela ','Mikee','1993-02-16','micahnazal@gmail.com','09175856922','dynaslope,community'),(30,'Pagaduan','Pauline','Pau','1992-07-14','prpagaduan@gmail.com','09176273628','dynaslope,community'),(31,'Peña','Mark Laurence','Macky','1991-05-13','marklaurence07@gmail.com','09185911639',''),(32,'Rafin','Reynante','Reyn','1981-08-12','rafinn30@gmail.com','09272520529','senslope,maintenance,deployment'),(33,'Razon','Kennex','Kennex','1989-12-29','kennexrazon@gmail.com','09293175812','senslope,accel-validation,alert'),(34,'Saturay','Ricarido','Jun','1981-06-10','ricsatjr@gmail.com','09228412065','dynaslope,monitoring,community,survey,alert'),(35,'Serato','Jo Hanna Lindsey','Zhey','1992-07-21','lindsey.serato@gmail.com','09176390841','senslope,datalogger,alert-mon'),(36,'Solidum','Sarah Allyssa','Sky','1993-06-02','skysolidum@gmail.com ','09177016527','senslope,accel-validation,alert'),(37,'Tabada','Dexter','Dex','1992-02-11','drtabada1@gmail.com','09165277584','senslope,maintenance,deployment'),(38,'Teodoro','Melody','Lem','1988-06-13','lem.teodoro@gmail.com','09177092413','dynaslope,community,alert-mon'),(39,'Veracruz','Nathan Azriel','Nathan','1991-11-26','nasveracruz@gmail.com','09165085567','dynaslope,survey'),(40,'Viernes','Meryll Angelica','Meryll','1993-04-18','meryllviernes08@gmail.com','09171444868','dynaslope,monitoring,alert'),(41,'Yague','Erika Isabel','Aika','1993-10-15','aikayague@gmail.com','09175365574','dynaslope,community'),(42,'Geliberte','John','John','1994-01-01','gelib@gmail.com','09554288976',' senslope'),(43,'Community','Community phone','Community',NULL,NULL,'09175048863,09499942312','alert,community'),(44,'Romerde','Mart Jeremiah','Mart','1993-09-06','mjromerde19@gmail.com','09177984489','dynaslope,monitoring'),(45,'Cruz','Brainerd','Brain','1994-10-22','brainerd.cruz@gmail.com','09272164380','senslope,maintenance'),(46,'Tabanao','Anne Marionne','Anne','1991-04-19','amntabanao@gmail.com','09178357034','dynaslope,community'),(47,'Moncada','Fatima','Pati','1993-04-28','moncada.fatima@gmail.com','09273706452','senslope,community'),(48,'Flores','Kate Justine','Kate','1992-08-02','katejustineflores@gmail.com','09955295126','senslope,accel'),(49,'Estrada','Rodney','Rodney','1992-07-12','rodney27500@gmail.com','09954212006,09298212470','senslope,maintenance,deployment'),(50,'Orravan','Orutra','Ultraman','0000-00-00',NULL,'09980619501','senslope,web,automations'),(51,'SMART','Server','SS','2010-01-01','dewsl.monitoring2@gmail.com','09988448687','gsm,server,smart'),(52,NULL,'Marge','Marge',NULL,'margaritadizon@phivolcs.dost.gov.ph','09158990172','alert'),(53,NULL,'Kim','Kim',NULL,'kimberleyvitto@phivolcs.dost.gove.ph','09984979870','alert'),(54,'Unang Una','Test','Proto One','1995-01-01','test@gmail.com','09168888888','test,prototype'),(55,'GLOBE','Server','SG','2010-01-01','test@senslope.com','09176321023','gsm,server,globe'),(56,'Capina','Marjorie','Momay','1994-02-26','mtcapina1@gmail.com','09274192791','dynaslope,community'),(57,'Geliberte','John','John','1994-01-01','gelib@gmail.com','09554288976','swat, senslope'),(58,'SMART 2','Server-smart 2','SS2','2016-11-25','updews@gmail.com','09999913206','server,smart'),(59,'Solidum','Renato','RUS','1950-01-01','rusolidum@phivolcs.dost.gov.ph','09178419215','rus,director'),(60,'Daag','Arturo','ASD','1950-01-01','asdaag@yahoo.com','09179958450','phivolcs,asd'),(61,'Dela Cruz','Kevin Dhale','Kevin','1995-01-01','random@test.com','09773922070','swat,senslope'),(62,'Gabriel','Marvin Vidal','Marvin','1994-09-13','vmgmarv@gmail.com','09773146253','dynaslope,monitoring'),(63,'Dilig','Ivy Jean','Ivy','1994-04-19','diligivyjean@gmail.com','09065310825','senslope,swat'),(64,'Guevarra','John David','David','1995-06-30','david063095@gmail.com','09056676763','swat, senslope'),(65,'Nepomuceno','John Louie','Louie','1995-01-01','jlouienepomuceno@gmail.com','09178141909','swat, senslope'),(66,'testing','Marvintest','nick','2018-12-31','gelibertest@gmail.com','09061495051','swat'),(73,'BEATSSAMPLE','Marvin','Samplers','1995-01-12','sample@gmail.com','091234567','undefined');
/*!40000 ALTER TABLE `dewslcontacts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2018-05-21 12:20:31
