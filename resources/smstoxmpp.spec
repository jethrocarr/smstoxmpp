Summary: A daemon to exchange messages between SMS gateway devices and XMPP
Name: smstoxmpp
Version: 0.0.1
Release: 5%{dist}
License: AGPLv3
URL: http://projects.jethrocarr.com/p/oss-smstoxmpp
Group: Applications/Internet
Source0: smstoxmpp-%{version}.tar.bz2

BuildRoot: %{_tmppath}/%{name}-%{version}-%{release}-root-%(%{__id_u} -n)
BuildArch: noarch
BuildRequires: gettext

Requires: httpd
Requires: php >= 5.3.0, php-xml, php-cli, php-process


%description
SMStoXMPP is a daemon which provides a gateway for exchange messages between
SMS gateways and XMPP accounts.


%prep
%setup -q -n smstoxmpp-%{version}

%build


%install
rm -rf $RPM_BUILD_ROOT
mkdir -p -m0755 $RPM_BUILD_ROOT%{_sysconfdir}/smstoxmpp/
mkdir -p -m0755 $RPM_BUILD_ROOT%{_datadir}/smstoxmpp/

# install application files and resources
cp -pr * $RPM_BUILD_ROOT%{_datadir}/smstoxmpp/


# install configuration file
install -m0700 app/config/sample_config.ini $RPM_BUILD_ROOT%{_sysconfdir}/smstoxmpp/config.ini
ln -s %{_sysconfdir}/smstoxmpp/config.ini $RPM_BUILD_ROOT%{_datadir}/smstoxmpp/app/config/config.ini

# install the apache configuration file
mkdir -p $RPM_BUILD_ROOT%{_sysconfdir}/httpd/conf.d
install -m 644 resources/smstoxmpp-httpdconfig.conf $RPM_BUILD_ROOT%{_sysconfdir}/httpd/conf.d/smstoxmpp.conf

# symlink the daemon
mkdir -p $RPM_BUILD_ROOT%{_bindir}/
ln -s %{_datadir}/smstoxmpp/app/dispatcher.php $RPM_BUILD_ROOT%{_bindir}/smstoxmppd

# install the daemon bootscript
mkdir -p $RPM_BUILD_ROOT/etc/init.d/
mkdir -p $RPM_BUILD_ROOT%{_localstatedir}/lib/smstoxmpp/
install -m 755 resources/smstoxmppd.rcsysinit $RPM_BUILD_ROOT/etc/init.d/smstoxmppd


%post

# Reload apache
echo "Reloading httpd..."
/etc/init.d/httpd reload

if [ $1 == 0 ];
then
	# upgrading existing rpm
	echo "Restarting daemon process..."
	/etc/init.d/smstoxmppd restart
fi

%preun

# stop running process
/etc/init.d/smstoxmppd stop



%clean
rm -rf $RPM_BUILD_ROOT

%files
%defattr(-,root,root)
%config %dir %{_sysconfdir}/smstoxmpp
%attr(770,root,apache) %config(noreplace) %{_sysconfdir}/smstoxmpp/config.ini
%attr(660,root,apache) %config(noreplace) %{_sysconfdir}/httpd/conf.d/smstoxmpp.conf
%{_datadir}/smstoxmpp/app
%{_datadir}/smstoxmpp/resources
%{_datadir}/smstoxmpp/docs/
%{_bindir}/smstoxmppd
/etc/init.d/smstoxmppd
%attr(770,root,nobody) %{_localstatedir}/lib/smstoxmpp
%docdir %{_datadir}/smstoxmpp/docs/


%changelog
* Mon Mar 11 2013 Jethro Carr <jethro.carr@jethrocarr.com> 0.0.1-1
- Pre-alpha release for testing & bug fixing

