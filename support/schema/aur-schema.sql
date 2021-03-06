-- The MySQL database layout for the AUR.  Certain data
-- is also included such as AccountTypes, etc.
--
DROP DATABASE IF EXISTS AUR;
CREATE DATABASE AUR DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE AUR;

-- Define the Account Types for the AUR.
--
CREATE TABLE AccountTypes (
	ID TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
	AccountType VARCHAR(32) NOT NULL DEFAULT '',
	PRIMARY KEY (ID)
) ENGINE = InnoDB;
INSERT INTO AccountTypes (ID, AccountType) VALUES (1, 'User');
INSERT INTO AccountTypes (ID, AccountType) VALUES (2, 'Trusted User');
INSERT INTO AccountTypes (ID, AccountType) VALUES (3, 'Developer');


-- User information for each user regardless of type.
--
CREATE TABLE Users (
	ID INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	AccountTypeID TINYINT UNSIGNED NOT NULL DEFAULT 1,
	Suspended TINYINT UNSIGNED NOT NULL DEFAULT 0,
	Username VARCHAR(32) NOT NULL,
	Email VARCHAR(64) NOT NULL,
	Passwd CHAR(32) NOT NULL,
	Salt CHAR(32) NOT NULL DEFAULT '',
	ResetKey CHAR(32) NOT NULL DEFAULT '',
	RealName VARCHAR(64) NOT NULL DEFAULT '',
	LangPreference VARCHAR(5) NOT NULL DEFAULT 'en',
	IRCNick VARCHAR(32) NOT NULL DEFAULT '',
	PGPKey VARCHAR(40) NULL DEFAULT NULL,
	LastVoted BIGINT UNSIGNED NOT NULL DEFAULT 0,
	LastLogin BIGINT UNSIGNED NOT NULL DEFAULT 0,
	LastLoginIPAddress INTEGER UNSIGNED NOT NULL DEFAULT 0,
	InactivityTS BIGINT UNSIGNED NOT NULL DEFAULT 0,
	RegistrationTS TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (ID),
	UNIQUE (Username),
	UNIQUE (Email),
	INDEX (AccountTypeID),
	FOREIGN KEY (AccountTypeID) REFERENCES AccountTypes(ID) ON DELETE NO ACTION
) ENGINE = InnoDB;
-- A default developer account for testing purposes
INSERT INTO Users (ID, AccountTypeID, Username, Email, Passwd) VALUES (
	1, 3, 'dev', 'dev@localhost', MD5('dev'));
INSERT INTO Users (ID, AccountTypeID, Username, Email, Passwd) VALUES (
	2, 2, 'tu', 'tu@localhost', MD5('tu'));
INSERT INTO Users (ID, AccountTypeID, Username, Email, Passwd) VALUES (
	3, 1, 'user', 'user@localhost', MD5('user'));


-- Track Users logging in/out of AUR web site.
--
CREATE TABLE Sessions (
	UsersID INTEGER UNSIGNED NOT NULL,
	SessionID CHAR(32) NOT NULL,
	LastUpdateTS BIGINT UNSIGNED NOT NULL,
	FOREIGN KEY (UsersID) REFERENCES Users(ID) ON DELETE CASCADE,
	UNIQUE (SessionID)
) ENGINE = InnoDB;


-- Categories for grouping packages when they reside in
-- Unsupported or the AUR - based on the categories defined
-- in 'extra'.
--
CREATE TABLE PackageCategories (
	ID TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
	Category VARCHAR(32) NOT NULL,
	PRIMARY KEY (ID)
) ENGINE = InnoDB;
INSERT INTO PackageCategories (Category) VALUES ('none');
INSERT INTO PackageCategories (Category) VALUES ('daemons');
INSERT INTO PackageCategories (Category) VALUES ('devel');
INSERT INTO PackageCategories (Category) VALUES ('editors');
INSERT INTO PackageCategories (Category) VALUES ('emulators');
INSERT INTO PackageCategories (Category) VALUES ('games');
INSERT INTO PackageCategories (Category) VALUES ('gnome');
INSERT INTO PackageCategories (Category) VALUES ('i18n');
INSERT INTO PackageCategories (Category) VALUES ('kde');
INSERT INTO PackageCategories (Category) VALUES ('lib');
INSERT INTO PackageCategories (Category) VALUES ('modules');
INSERT INTO PackageCategories (Category) VALUES ('multimedia');
INSERT INTO PackageCategories (Category) VALUES ('network');
INSERT INTO PackageCategories (Category) VALUES ('office');
INSERT INTO PackageCategories (Category) VALUES ('science');
INSERT INTO PackageCategories (Category) VALUES ('system');
INSERT INTO PackageCategories (Category) VALUES ('x11');
INSERT INTO PackageCategories (Category) VALUES ('xfce');
INSERT INTO PackageCategories (Category) VALUES ('fonts');


-- Information about the actual packages
--
CREATE TABLE Packages (
	ID INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	Name VARCHAR(64) NOT NULL,
	Version VARCHAR(32) NOT NULL DEFAULT '',
	CategoryID TINYINT UNSIGNED NOT NULL DEFAULT 1,
	Description VARCHAR(255) NOT NULL DEFAULT "An Arch Package",
	URL VARCHAR(255) NOT NULL DEFAULT "https://www.archlinux.org",
	License VARCHAR(40) NOT NULL DEFAULT '',
	NumVotes INTEGER UNSIGNED NOT NULL DEFAULT 0,
	OutOfDateTS BIGINT UNSIGNED NULL DEFAULT NULL,
	SubmittedTS BIGINT UNSIGNED NOT NULL,
	ModifiedTS BIGINT UNSIGNED NOT NULL,
	SubmitterUID INTEGER UNSIGNED NULL DEFAULT NULL,     -- who submitted it?
	MaintainerUID INTEGER UNSIGNED NULL DEFAULT NULL,    -- User
	PRIMARY KEY (ID),
	UNIQUE (Name),
	INDEX (CategoryID),
	INDEX (NumVotes),
	INDEX (SubmitterUID),
	INDEX (MaintainerUID),
	FOREIGN KEY (CategoryID) REFERENCES PackageCategories(ID) ON DELETE NO ACTION,
	-- deleting a user will cause packages to be orphaned, not deleted
	FOREIGN KEY (SubmitterUID) REFERENCES Users(ID) ON DELETE SET NULL,
	FOREIGN KEY (MaintainerUID) REFERENCES Users(ID) ON DELETE SET NULL
) ENGINE = InnoDB;


-- Track which dependencies a package has
--
CREATE TABLE PackageDepends (
	PackageID INTEGER UNSIGNED NOT NULL,
	DepName VARCHAR(64) NOT NULL,
	DepCondition VARCHAR(20),
	INDEX (PackageID),
	INDEX (DepName),
	FOREIGN KEY (PackageID) REFERENCES Packages(ID) ON DELETE CASCADE
) ENGINE = InnoDB;


-- Track which sources a package has
--
CREATE TABLE PackageSources (
	PackageID INTEGER UNSIGNED NOT NULL,
	Source VARCHAR(255) NOT NULL DEFAULT "/dev/null",
	INDEX (PackageID),
	FOREIGN KEY (PackageID) REFERENCES Packages(ID) ON DELETE CASCADE
) ENGINE = InnoDB;


-- Track votes for packages
--
CREATE TABLE PackageVotes (
	UsersID INTEGER UNSIGNED NOT NULL,
	PackageID INTEGER UNSIGNED NOT NULL,
	INDEX (UsersID),
	INDEX (PackageID),
	FOREIGN KEY (UsersID) REFERENCES Users(ID) ON DELETE CASCADE,
	FOREIGN KEY (PackageID) REFERENCES Packages(ID) ON DELETE CASCADE
) ENGINE = InnoDB;
CREATE UNIQUE INDEX VoteUsersIDPackageID ON PackageVotes (UsersID, PackageID);

-- Record comments for packages
--
CREATE TABLE PackageComments (
	ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	PackageID INTEGER UNSIGNED NOT NULL,
	UsersID INTEGER UNSIGNED NOT NULL,
	Comments TEXT NOT NULL DEFAULT '',
	CommentTS BIGINT UNSIGNED NOT NULL DEFAULT 0,
	DelUsersID INTEGER UNSIGNED NULL DEFAULT NULL,
	PRIMARY KEY (ID),
	INDEX (UsersID),
	INDEX (PackageID),
	FOREIGN KEY (UsersID) REFERENCES Users(ID) ON DELETE CASCADE,
	FOREIGN KEY (DelUsersID) REFERENCES Users(ID) ON DELETE CASCADE,
	FOREIGN KEY (PackageID) REFERENCES Packages(ID) ON DELETE CASCADE
) ENGINE = InnoDB;

-- Comment addition notifications
--
CREATE TABLE CommentNotify (
	PkgID INTEGER UNSIGNED NOT NULL,
	UserID INTEGER UNSIGNED NOT NULL,
	FOREIGN KEY (PkgID) REFERENCES Packages(ID) ON DELETE CASCADE,
	FOREIGN KEY (UserID) REFERENCES Users(ID) ON DELETE CASCADE
) ENGINE = InnoDB;
CREATE UNIQUE INDEX NotifyUserIDPkgID ON CommentNotify (UserID, PkgID);

-- Package name blacklist
--
CREATE TABLE PackageBlacklist (
	ID INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	Name VARCHAR(64) NOT NULL,
	PRIMARY KEY (ID),
	UNIQUE (Name)
) ENGINE = InnoDB;

-- Vote information
--
CREATE TABLE IF NOT EXISTS TU_VoteInfo (
  ID int(10) unsigned NOT NULL auto_increment,
  Agenda text NOT NULL,
  User VARCHAR(32) NOT NULL,
  Submitted bigint(20) unsigned NOT NULL,
  End bigint(20) unsigned NOT NULL,
  Quorum decimal(2, 2) unsigned NOT NULL,
  SubmitterID int(10) unsigned NOT NULL,
  Yes tinyint(3) unsigned NOT NULL default '0',
  No tinyint(3) unsigned NOT NULL default '0',
  Abstain tinyint(3) unsigned NOT NULL default '0',
  ActiveTUs tinyint(3) unsigned NOT NULL default '0',
  PRIMARY KEY  (ID),
  FOREIGN KEY (SubmitterID) REFERENCES Users(ID) ON DELETE CASCADE
) ENGINE = InnoDB;

-- Individual vote records
--
CREATE TABLE IF NOT EXISTS TU_Votes (
  VoteID int(10) unsigned NOT NULL,
  UserID int(10) unsigned NOT NULL,
  FOREIGN KEY (VoteID) REFERENCES TU_VoteInfo(ID) ON DELETE CASCADE,
  FOREIGN KEY (UserID) REFERENCES Users(ID) ON DELETE CASCADE
) ENGINE = InnoDB;

-- Malicious user banning
--
CREATE TABLE Bans (
	IPAddress INTEGER UNSIGNED NOT NULL DEFAULT 0,
	BanTS TIMESTAMP NOT NULL,
	PRIMARY KEY (IPAddress)
) ENGINE = InnoDB;
