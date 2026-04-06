-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: mytube_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `schools`
--

LOCK TABLES `schools` WRITE;
/*!40000 ALTER TABLE `schools` DISABLE KEYS */;
INSERT INTO `schools` VALUES (1,'Col├®gio Angolano de Talatona','CAT',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(2,'Escola Portuguesa de Luanda','EPL',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(3,'Col├®gio S├úo Francisco de Assis','CSFA',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(4,'Escola Internacional de Luanda','EIL',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(5,'Col├®gio Pitaval','CP',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(6,'Externato Rainha Santa Isabel','ERSI',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(7,'Col├®gio Madre Lu├¡sa Mafo','CMLM',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(8,'Escola Secund├íria do Alvalade','ESA',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(9,'Escola Mutu Ya Kevela','EMYK',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(10,'Col├®gio Dom Bosco','CDB',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(11,'Col├®gio ABC','CABC',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(12,'Escola Secund├íria da Maianga','ESM',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(13,'Instituto M├®dio Industrial de Luanda','IMIL',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(14,'Complexo Escolar Privado Internacional','CEPI',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(15,'Escola Horizonte','EH',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-01 18:23:19','2026-03-01 18:23:19'),(16,'42Luanda','42LDA',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-02 17:40:24','2026-03-02 17:40:24'),(17,'Col├®gio Dembo','CD',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(18,'Col├®gio Lenoly','CL',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(19,'Col├®gio Nossa Senhora Da Anuncia├º├úo','CNSA',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(20,'Col├®gio Nova Estrela','CNE',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(21,'Col├®gio O Cantinho da Vany Lda','COCV',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(22,'Complexo Escolar Privado ABC Welwitschia Mirabilis','CEPWM',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(23,'Complexo Escolar Privado A Flor do Saber','CEPAFS',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(24,'Complexo Escolar Privado Emanuel Kutolola','CEPEK',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(25,'Complexo Escolar Sanjuluka','CES',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(26,'Instituto T├®cnico Privado de Sa├║de Santa Rosa','ITPSSR',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(27,'Instituto T├®cnico De Sa├║de Privado Pingos de Arvoredos','ITSPPA',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(28,'Instituto T├®cnico Privado de Sa├║de Tim├│teo Ulika','ITPSTU',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(29,'Complexo Escolar Bet├ónia Zango-III','CEBZ3',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(30,'Complexo Escolar Bet├ónia Zango-I','CEBZ1',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(31,'Escola de Futebol do Zango - EFZ','EFZ',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(32,'Bernadino de Jesus da cunha BJC','BJC',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(33,'Luzia Laura Leonardo LLL','LLL',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-04 20:32:44','2026-03-04 20:32:44'),(34,'Complexo escolar privado o imperador','Imperador',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-15 18:01:23','2026-03-15 18:01:58'),(35,'Complexo Escolar Privado Bet├ónia Zango - I','BetaniaZango1',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-15 18:01:23','2026-03-15 18:01:58'),(36,'Complexo Escolar Privado Bet├ónia Zango - III','BetaniaZango3',NULL,'Luanda','Luanda',0,0,0,1,'2026-03-15 18:01:23','2026-03-15 18:01:58');
/*!40000 ALTER TABLE `schools` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-06  9:41:54
